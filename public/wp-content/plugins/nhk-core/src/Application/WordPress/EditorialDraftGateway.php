<?php
declare(strict_types=1);

namespace NHK\Core\Application\WordPress;

use NHK\Core\Contracts\Article\ArticleOperationReceiptRepository;
use NHK\Core\Contracts\WordPress\EditorialPostStore;
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt, EditorialPostState, EditorialStateToken};
use NHK\Core\Application\Article\ArticlePublicationGate;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Contracts\Article\{OwnerPublicationService, PublicationPrincipal};
use NHK\Core\Application\Capture\CaptureEditorialWriteGuard;
use NHK\Core\Application\Semantic\{ManagedArticleSectionConflict, ManagedArticleSectionParser};

final class EditorialDraftGateway
{
    public function __construct(private EditorialPostStore $posts, private ArticleOperationReceiptRepository $receipts, private ?OwnerPublicationService $ownerPublication = null) {}

    /** @param array<string,mixed> $input */
    public function create(array $input): array
    {
        $key = trim((string) ($input['idempotency_key'] ?? '')); if ($key === '') throw new \InvalidArgumentException('Editorial draft idempotency key is required.');
        $fingerprintInput = $input; unset($fingerprintInput['operation_id']); $fingerprint = hash('sha256', json_encode($fingerprintInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $existing = $this->receipts->findByIdempotencyKey($key);
        if ($existing !== null) { if (!hash_equals($existing->requestFingerprint, $fingerprint)) return ['ok' => false, 'reason' => 'IDEMPOTENCY_CONFLICT', 'receipt' => $existing->toArray()]; return $this->result($existing, $existing->wpPostId === null ? null : $this->posts->read($existing->wpPostId)); }
        $research = is_array($input['research'] ?? null) ? $input['research'] : [];
        if (($research['ready_for_draft'] ?? true) === false) return ['ok' => false, 'reason' => 'RESEARCH_PREFLIGHT_BLOCKED', 'research' => $research];
        $captureOwned = trim((string) ($input['capture_id'] ?? '')) !== '';
        if ($captureOwned) CaptureEditorialWriteGuard::enter();
        try {
            $state = $this->posts->createDraft(['post_title' => (string) ($input['title'] ?? ''), 'post_content' => (string) ($input['content'] ?? ''), 'post_excerpt' => (string) ($input['excerpt'] ?? ''), 'post_author' => (int) ($input['author'] ?? 0)]);
            $state = $this->ensureNativeRoute($state);
        } finally {
            if ($captureOwned) CaptureEditorialWriteGuard::leave();
        }
        $receipt = $this->receipts->create(new ArticleOperationReceipt((string) ($input['operation_id'] ?? UuidCodec::newV7()), $key, $fingerprint, 'create', $state->endpointKey, $state->postId, 'draft', ArticleIngestOutcome::GOVERNANCE_PENDING, false, [], [], [], 1, null, null, $state->token, [], [], [], ['publication_blockers' => ['DRAFT_INCOMPLETE_FOR_PUBLICATION'], 'research' => $research]));
        return $this->result($receipt, $state);
    }

    private function ensureNativeRoute(EditorialPostState $state): EditorialPostState
    {
        if ($state->slug !== '' || $state->permalink !== '' || $state->title === '') return $state;
        $slug = function_exists('sanitize_title') ? (string) sanitize_title($state->title) : $this->fallbackSlug($state->title);
        if ($slug === '') $slug = 'article-' . $state->postId;
        $this->posts->update($state->postId, ['post_name' => $slug]);
        $readBack = $this->posts->read($state->postId);
        if ($readBack === null || $readBack->slug === '' || $readBack->permalink === '') throw new \RuntimeException('EDITORIAL_NATIVE_ROUTE_READBACK_FAILED');
        return $readBack;
    }

    private function fallbackSlug(string $title): string
    {
        $value = function_exists('iconv') ? (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) : $title;
        $value = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));
        return $value;
    }

    /** @param array<string,mixed> $fields */
    public function update(int $postId, array $fields, string $expectedStateToken, string $captureId = ''): array
    {
        $current = $this->posts->read($postId); if ($current === null) return ['ok' => false, 'reason' => 'WP_POST_UNAVAILABLE'];
        if (!EditorialStateToken::matches($expectedStateToken, $current)) return ['ok' => false, 'reason' => 'EDITORIAL_STATE_CONFLICT', 'post' => $current->snapshot(), 'state_token' => $current->token];
        // Existing public Articles are valid lifecycle targets. The native
        // Post remains public while its editorial fields are CAS-updated; the
        // caller must not smuggle a status transition through this boundary.
        if (!in_array($current->status, ['draft', 'publish'], true)) return ['ok' => false, 'reason' => 'EDITORIAL_UPDATE_NOT_ELIGIBLE'];
        $managedExpectations = is_array($fields['managed_section_expectations'] ?? null) ? $fields['managed_section_expectations'] : [];
        unset($fields['managed_section_expectations']);
        if ($managedExpectations !== []) {
            try { (new ManagedArticleSectionParser())->removeOwned($current->content, array_values(array_filter($managedExpectations, 'is_array'))); }
            catch (ManagedArticleSectionConflict $error) { return ['ok' => false, 'reason' => 'EDITORIAL_CONFLICT', 'post' => $current->snapshot(), 'state_token' => $current->token, 'conflict' => $error->getMessage()]; }
        }
        // A successful CAS with no effective editorial delta must remain a
        // read-only operation. In particular, never call wp_update_post for
        // an empty/no-op packet: native WordPress may still advance
        // post_modified_gmt or invoke write-capable hooks for it.
        if ($fields === [] || $this->isNoop($current, $fields)) {
            return ['ok' => true, 'post' => $current->snapshot(), 'state_token' => $current->token, 'publication_blockers' => $current->status === 'publish' ? [] : ['DRAFT_INCOMPLETE_FOR_PUBLICATION']];
        }
        $captureOwned = trim($captureId) !== '';
        if ($captureOwned) CaptureEditorialWriteGuard::enter();
        try {
            $updated = $this->posts->update($postId, $fields);
        } finally {
            if ($captureOwned) CaptureEditorialWriteGuard::leave();
        }
        return ['ok' => true, 'post' => $updated->snapshot(), 'state_token' => $updated->token, 'publication_blockers' => $updated->status === 'publish' ? [] : ['DRAFT_INCOMPLETE_FOR_PUBLICATION']];
    }

    /** @param array<string,mixed> $fields */
    private function isNoop(EditorialPostState $current, array $fields): bool
    {
        $map = [
            'post_title' => 'title',
            'post_content' => 'content',
            'post_excerpt' => 'excerpt',
            'post_name' => 'slug',
        ];
        foreach ($fields as $field => $value) {
            if (!isset($map[$field])) return false;
            if ((string) $value !== (string) $current->{$map[$field]}) return false;
        }
        return true;
    }

    /** Publish only after every cross-boundary verification has been supplied and passed. */
    public function publish(int $postId, string $expectedStateToken, array $evidence, string $idempotencyKey): array
    {
        if ($this->ownerPublication !== null) return $this->ownerPublication->request($postId, $expectedStateToken, $evidence, $idempotencyKey, new PublicationPrincipal(function_exists('get_current_user_id') ? (string) get_current_user_id() : '0', 'mcp', ''));
        return $this->transition($postId, $expectedStateToken, $idempotencyKey, 'publish', $evidence);
    }

    /** Review publication readiness without invoking the native WordPress writer. */
    public function reviewPublication(int $postId, string $expectedStateToken, array $evidence, string $idempotencyKey): array
    {
        if ($this->ownerPublication !== null) return $this->ownerPublication->review($postId, $expectedStateToken, $evidence, $idempotencyKey, new PublicationPrincipal(function_exists('get_current_user_id') ? (string) get_current_user_id() : '0', 'mcp', ''));
        $current = $this->posts->read($postId);
        if ($current === null) return ['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['WP_POST_UNAVAILABLE']];
        $gate = (new ArticlePublicationGate())->check($current, $evidence, $expectedStateToken);
        return $gate->toArray() + ['post' => $current->snapshot(), 'state_token' => $current->token];
    }

    /** @param array<string,mixed> $evidence */
    public function approvePublication(int $postId, string $expectedStateToken, array $evidence, string $idempotencyKey, string $decisionId, string $affirmation, string $principalId, string $requestReference = ''): array
    {
        if ($this->ownerPublication === null) return ['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['OWNER_PUBLICATION_SERVICE_UNAVAILABLE']];
        return $this->ownerPublication->approveAndPublish($postId, $expectedStateToken, $evidence, $idempotencyKey, $decisionId, new PublicationPrincipal($principalId, 'mcp', $requestReference), $affirmation);
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
            $expectedStatus = match ($intent) {
                'publish' => 'publish',
                'trash' => 'trash',
                'restore' => 'draft',
                default => null,
            };
            return ['ok' => $state !== null && ($expectedStatus === null || $state->status === $expectedStatus), 'post' => $state?->snapshot(), 'receipt' => $existing->toArray()];
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
            $state = $current->status === 'trash' ? $current : $this->posts->trash($postId);
            $readBack = $this->posts->read($postId);
            if ($readBack === null || $readBack->status !== 'trash') {
                $receipt = $this->receipts->create(new ArticleOperationReceipt(UuidCodec::newV7(), $idempotencyKey, $fingerprint, $intent, $current->endpointKey, $current->postId, 'editorial', ArticleIngestOutcome::VERIFICATION_FAILED, true, [], [], ['code' => 'EDITORIAL_TRASH_READBACK_FAILED'], 1, null, null, $readBack?->token ?? $current->token, [], [], [], ['expected_status' => 'trash', 'observed_status' => $readBack?->status ?? 'unavailable']));
                return ['ok' => false, 'reason' => 'EDITORIAL_TRASH_READBACK_FAILED', 'post' => $readBack?->snapshot(), 'state_token' => $readBack?->token ?? $current->token, 'receipt' => $receipt->toArray()];
            }
            $state = $readBack;
        } else {
            if ($current->status === 'trash') $state = $this->posts->restore($postId); else $state = $current;
        }
        if ($intent === 'publish') {
            $readBack = $this->posts->read($postId);
            if ($readBack === null || $readBack->status !== 'publish' || $readBack->slug === '' || $readBack->permalink === '') {
                $receipt = $this->receipts->create(new ArticleOperationReceipt(UuidCodec::newV7(), $idempotencyKey, $fingerprint, $intent, $current->endpointKey, $current->postId, 'editorial', ArticleIngestOutcome::VERIFICATION_FAILED, true, [], [], ['code' => 'EDITORIAL_PUBLIC_ROUTE_READBACK_FAILED'], 1, null, null, $readBack?->token ?? $current->token, [], [], [], ['expected_status' => 'publish', 'observed_status' => $readBack?->status ?? 'unavailable', 'slug_present' => $readBack?->slug !== '', 'permalink_present' => $readBack?->permalink !== '']));
                return ['ok' => false, 'reason' => 'EDITORIAL_PUBLIC_ROUTE_READBACK_FAILED', 'post' => $readBack?->snapshot(), 'state_token' => $readBack?->token ?? $current->token, 'receipt' => $receipt->toArray()];
            }
            $state = $readBack;
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
