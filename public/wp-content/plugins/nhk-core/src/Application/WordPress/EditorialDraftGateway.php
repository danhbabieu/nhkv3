<?php
declare(strict_types=1);

namespace NHK\Core\Application\WordPress;

use NHK\Core\Contracts\Article\ArticleOperationReceiptRepository;
use NHK\Core\Contracts\WordPress\EditorialPostStore;
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt, EditorialPostState};
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

    /** @return array<string,mixed> */
    private function result(ArticleOperationReceipt $receipt, ?EditorialPostState $state): array { return ['ok' => $state !== null, 'post' => $state?->snapshot(), 'post_id' => $state?->postId, 'state_token' => $state?->token ?? $receipt->wpStateToken, 'receipt' => $receipt->toArray(), 'publication_blockers' => ['DRAFT_INCOMPLETE_FOR_PUBLICATION'], 'next_stage' => 'SEMANTIC_GOVERNANCE']; }
}
