<?php
declare(strict_types=1);

namespace NHK\Core\Application\WordPress;

use NHK\Core\Contracts\Article\ArticleOperationReceiptRepository;
use NHK\Core\Contracts\WordPress\EditorialPostStore;
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt, EditorialPostState};
use NHK\Core\Application\Article\ArticlePublicationGate;
use NHK\Core\Shared\Uuid\UuidCodec;

final class EditorialDraftGateway
{
    public function __construct(private EditorialPostStore $posts, private ArticleOperationReceiptRepository $receipts) {}

    /** @param array<string,mixed> $input */
    public function create(array $input): array
    {
        $key = trim((string) ($input['idempotency_key'] ?? '')); if ($key === '') throw new \InvalidArgumentException('Editorial draft idempotency key is required.');
        $fingerprintInput = $input; unset($fingerprintInput['operation_id']); $fingerprint = hash('sha256', json_encode($fingerprintInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $existing = $this->receipts->findByIdempotencyKey($key);
        if ($existing !== null) { if (!hash_equals($existing->requestFingerprint, $fingerprint)) return ['ok' => false, 'reason' => 'IDEMPOTENCY_CONFLICT', 'receipt' => $existing->toArray()]; return $this->result($existing, $existing->wpPostId === null ? null : $this->posts->read($existing->wpPostId)); }
        $research = is_array($input['research'] ?? null) ? $input['research'] : [];
        if (($research['ready_for_draft'] ?? true) === false) return ['ok' => false, 'reason' => 'RESEARCH_PREFLIGHT_BLOCKED', 'research' => $research];
        $state = $this->posts->createDraft(['post_title' => (string) ($input['title'] ?? ''), 'post_content' => (string) ($input['content'] ?? ''), 'post_excerpt' => (string) ($input['excerpt'] ?? ''), 'post_author' => (int) ($input['author'] ?? 0)]);
        $receipt = $this->receipts->create(new ArticleOperationReceipt((string) ($input['operation_id'] ?? UuidCodec::newV7()), $key, $fingerprint, 'create', $state->endpointKey, $state->postId, 'draft', ArticleIngestOutcome::GOVERNANCE_PENDING, false, [], [], [], 1, null, null, $state->token, [], [], [], ['publication_blockers' => ['DRAFT_INCOMPLETE_FOR_PUBLICATION'], 'research' => $research]));
        return $this->result($receipt, $state);
    }

    /** @param array<string,mixed> $fields */
    public function update(int $postId, array $fields, string $expectedStateToken): array
    {
        $current = $this->posts->read($postId); if ($current === null) return ['ok' => false, 'reason' => 'WP_POST_UNAVAILABLE'];
        if (!hash_equals($expectedStateToken, $current->token)) return ['ok' => false, 'reason' => 'EDITORIAL_STATE_CONFLICT', 'post' => $current->snapshot(), 'state_token' => $current->token];
        if ($current->status !== 'draft') return ['ok' => false, 'reason' => 'EDITORIAL_UPDATE_NOT_ELIGIBLE'];
        $updated = $this->posts->update($postId, $fields); return ['ok' => true, 'post' => $updated->snapshot(), 'state_token' => $updated->token, 'publication_blockers' => ['DRAFT_INCOMPLETE_FOR_PUBLICATION']];
    }

    /** Publish only after every cross-boundary verification has been supplied and passed. */
    public function publish(int $postId, string $expectedStateToken, array $evidence, string $idempotencyKey): array
    {
        return $this->transition($postId, $expectedStateToken, $idempotencyKey, 'publish', $evidence);
    }

    public function trash(int $postId, string $expectedStateToken, string $idempotencyKey): array
    {
        return $this->transition($postId, $expectedStateToken, $idempotencyKey, 'trash');
    }

    public function restore(int $postId, string $expectedStateToken, string $idempotencyKey): array
    {
        return $this->transition($postId, $expectedStateToken, $idempotencyKey, 'restore');
    }

    /** @param array<string,mixed> $evidence */
    private function transition(int $postId, string $expectedStateToken, string $idempotencyKey, string $intent, array $evidence = []): array
    {
        if ($idempotencyKey === '') return ['ok' => false, 'reason' => 'IDEMPOTENCY_KEY_REQUIRED'];
        $fingerprint = hash('sha256', json_encode(['post_id' => $postId, 'token' => $expectedStateToken, 'intent' => $intent, 'evidence' => $evidence], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $existing = $this->receipts->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            if (!hash_equals($existing->requestFingerprint, $fingerprint)) return ['ok' => false, 'reason' => 'IDEMPOTENCY_CONFLICT', 'receipt' => $existing->toArray()];
            $state = $existing->wpPostId === null ? null : $this->posts->read($existing->wpPostId);
            return ['ok' => $state !== null && ($intent !== 'publish' || $state->status === 'publish'), 'post' => $state?->snapshot(), 'receipt' => $existing->toArray()];
        }
        $current = $this->posts->read($postId);
        if ($current === null) return ['ok' => false, 'reason' => 'WP_POST_UNAVAILABLE'];
        if (!hash_equals($expectedStateToken, $current->token)) return ['ok' => false, 'reason' => 'EDITORIAL_STATE_CONFLICT', 'post' => $current->snapshot(), 'state_token' => $current->token];
        $publicationWarnings = [];
        if ($intent === 'publish') {
            $gate = (new ArticlePublicationGate())->check($current, $evidence, $expectedStateToken);
            if (!$gate->eligible) return ['ok' => false, 'reason' => 'PUBLICATION_BLOCKED', 'blockers' => $gate->blockers, 'post' => $current->snapshot()];
            $publicationWarnings = $gate->warnings;
            try {
                $state = $this->posts->publish($postId);
            } catch (\Throwable $error) {
                // A transport failure after the native transition is uncertain: read back
                // before retrying so a caller cannot create a second publication action.
                $readBack = $this->posts->read($postId);
                if ($readBack !== null && $readBack->status === 'publish') {
                    $state = $readBack;
                } else {
                    $receipt = $this->receipts->create(new ArticleOperationReceipt(UuidCodec::newV7(), $idempotencyKey, $fingerprint, $intent, $current->endpointKey, $current->postId, 'editorial', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, true, [], [], ['code' => 'PUBLICATION_RESULT_UNCERTAIN', 'error' => $error->getMessage()], 1, null, null, $current->token, [], [], [], ['publication_evidence' => $this->withoutBody($evidence)]));
                    return ['ok' => false, 'reason' => 'PUBLICATION_RESULT_UNCERTAIN', 'post' => $readBack?->snapshot(), 'receipt' => $receipt->toArray()];
                }
            }
        } elseif ($intent === 'trash') {
            if ($current->status === 'publish') $state = $this->posts->trash($postId); else $state = $current;
        } else {
            if ($current->status === 'trash') $state = $this->posts->restore($postId); else $state = $current;
        }
        $receipt = $this->receipts->create(new ArticleOperationReceipt(UuidCodec::newV7(), $idempotencyKey, $fingerprint, $intent, $state->endpointKey, $state->postId, 'editorial', ArticleIngestOutcome::COMPLETED, false, [], [], [], 1, null, null, $state->token, [], [], [], ['status' => $state->status], $this->withoutBody($evidence)));
        return ['ok' => true, 'post' => $state->snapshot(), 'state_token' => $state->token, 'receipt' => $receipt->toArray(), 'publication_warnings' => $publicationWarnings];
    }

    /** @return array<string,mixed> */
    private function result(ArticleOperationReceipt $receipt, ?EditorialPostState $state): array { return ['ok' => $state !== null, 'post' => $state?->snapshot(), 'post_id' => $state?->postId, 'state_token' => $state?->token ?? $receipt->wpStateToken, 'receipt' => $receipt->toArray(), 'publication_blockers' => ['DRAFT_INCOMPLETE_FOR_PUBLICATION'], 'next_stage' => 'SEMANTIC_GOVERNANCE']; }

    /** @return array<string,mixed> */
    private function withoutBody(array $value): array
    {
        foreach (['body', 'content', 'post_content'] as $key) unset($value[$key]);
        return $value;
    }
}
