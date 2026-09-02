<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Article;

use NHK\Core\Contracts\Article\ArticleOperationReceiptRepository;
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt};

final class WpdbArticleOperationReceiptRepository implements ArticleOperationReceiptRepository
{
    public function __construct(private ?object $database = null) {}

    private function db(): object
    {
        global $wpdb;
        return $this->database ?? $wpdb;
    }

    private function table(): string
    {
        return $this->db()->prefix . 'nhk_article_operations';
    }

    public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare('SELECT * FROM ' . $this->table() . ' WHERE idempotency_key=%s LIMIT 1', $key), defined('ARRAY_A') ? ARRAY_A : 1);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt
    {
        $db = $this->db();
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $db->query($db->prepare(
            'INSERT INTO ' . $this->table() . ' (operation_id,idempotency_key,request_fingerprint,intent,wp_endpoint_key,wp_post_id,wp_state_token,dependency_map_json,proposal_states_json,apply_attempts_json,stage,outcome,retryable,proposal_ids_json,applied_proposal_ids_json,failure_json,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s,%d,%s,%s)',
            $receipt->operationId,
            $receipt->idempotencyKey,
            $receipt->requestFingerprint,
            $receipt->intent,
            $receipt->wpEndpointKey,
            $receipt->wpPostId,
            $receipt->wpStateToken,
            wp_json_encode($receipt->dependencyMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode($receipt->proposalStates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode($receipt->applyAttempts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $receipt->stage,
            $receipt->outcome->value,
            $receipt->retryable ? 1 : 0,
            wp_json_encode($receipt->proposalIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode($receipt->appliedProposalIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode($receipt->failure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $receipt->revision,
            $now,
            $now,
        ));
        if ($ok === false) {
            $existing = $this->findByIdempotencyKey($receipt->idempotencyKey);
            if ($existing !== null) return $existing;
            throw new \RuntimeException('ARTICLE_RECEIPT_INSERT_FAILED');
        }
        return $this->findByIdempotencyKey($receipt->idempotencyKey) ?? $receipt;
    }

    public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt
    {
        $db = $this->db();
        $ok = $db->query($db->prepare(
            'UPDATE ' . $this->table() . ' SET stage=%s,outcome=%s,retryable=%d,proposal_ids_json=%s,applied_proposal_ids_json=%s,failure_json=%s,wp_endpoint_key=%s,wp_post_id=%s,wp_state_token=%s,dependency_map_json=%s,proposal_states_json=%s,apply_attempts_json=%s,revision=%d,updated_at=%s WHERE operation_id=%s AND revision=%d',
            $receipt->stage,
            $receipt->outcome->value,
            $receipt->retryable ? 1 : 0,
            wp_json_encode($receipt->proposalIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode($receipt->appliedProposalIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode($receipt->failure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $receipt->wpEndpointKey,
            $receipt->wpPostId,
            $receipt->wpStateToken,
            wp_json_encode($receipt->dependencyMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode($receipt->proposalStates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode($receipt->applyAttempts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $receipt->revision,
            gmdate('Y-m-d H:i:s.u'),
            $receipt->operationId,
            $receipt->revision - 1,
        ));
        if ($ok !== 1) throw new \RuntimeException('ARTICLE_RECEIPT_REVISION_CONFLICT');
        return $this->findByIdempotencyKey($receipt->idempotencyKey) ?? $receipt;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ?ArticleOperationReceipt
    {
        try {
            $proposalIds = json_decode((string) ($row['proposal_ids_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            $appliedIds = json_decode((string) ($row['applied_proposal_ids_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            $failure = json_decode((string) ($row['failure_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            $dependencyMap = json_decode((string) ($row['dependency_map_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            $proposalStates = json_decode((string) ($row['proposal_states_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            $applyAttempts = json_decode((string) ($row['apply_attempts_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($proposalIds) || !array_is_list($proposalIds) || !is_array($appliedIds) || !array_is_list($appliedIds) || !is_array($failure) || !is_array($dependencyMap) || !is_array($proposalStates) || !is_array($applyAttempts)) return null;
            return new ArticleOperationReceipt(
                (string) $row['operation_id'],
                (string) $row['idempotency_key'],
                (string) $row['request_fingerprint'],
                (string) $row['intent'],
                isset($row['wp_endpoint_key']) ? (string) $row['wp_endpoint_key'] : null,
                isset($row['wp_post_id']) && $row['wp_post_id'] !== null ? (int) $row['wp_post_id'] : null,
                (string) $row['stage'],
                ArticleIngestOutcome::from((string) $row['outcome']),
                (int) $row['retryable'] === 1,
                array_values(array_map('strval', $proposalIds)),
                array_values(array_map('strval', $appliedIds)),
                $failure,
                (int) $row['revision'],
                isset($row['created_at']) ? (string) $row['created_at'] : null,
                isset($row['updated_at']) ? (string) $row['updated_at'] : null,
                isset($row['wp_state_token']) ? (string) $row['wp_state_token'] : null,
                $dependencyMap,
                array_map('strval', $proposalStates),
                array_map('intval', $applyAttempts),
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
