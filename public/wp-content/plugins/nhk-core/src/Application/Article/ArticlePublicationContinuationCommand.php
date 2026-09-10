<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Contracts\Article\EditorialStateReader;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Article\EditorialPostState;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Orchestrates continuation for one existing Capture-owned Article.
 *
 * This command owns no WordPress mutation. Its invoker is the canonical MCP
 * Ability/application boundary, which keeps CLI and MCP on the same Gate,
 * Governance, owner-decision, CAS, idempotency and read-back path.
 */
final class ArticlePublicationContinuationCommand
{
    /**
     * @param callable(string,array<string,mixed>):array<string,mixed> $invokeAbility
     * @param callable():array{documentation_version:string,manifest_hash:string} $documentationCheckpoint
     * @param callable(string):array<string,mixed>|null $publicReadBack
     */
    public function __construct(
        private CaptureRepository $captures,
        private EditorialStateReader $articles,
        private $invokeAbility,
        private $documentationCheckpoint,
        private $publicReadBack = null,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function execute(array $input): array
    {
        $operation = strtolower(trim((string) ($input['operation'] ?? '')));
        if (!in_array($operation, ['run', 'review', 'approve', 'publish'], true)) return $this->blocked('PUBLICATION_OPERATION_INVALID');
        $captureId = trim((string) ($input['capture_id'] ?? ''));
        $articleId = (int) ($input['article_id'] ?? 0);
        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if (!UuidCodec::isValid($captureId)) return $this->blocked('CAPTURE_ID_REQUIRED');
        if ($articleId < 1) return $this->blocked('ARTICLE_ID_REQUIRED');
        if ($idempotencyKey === '') return $this->blocked('IDEMPOTENCY_KEY_REQUIRED');

        $capture = $this->captures->findById($captureId);
        if (!$capture instanceof CaptureRecord) return $this->blocked('CAPTURE_UNAVAILABLE', ['capture_id' => $captureId]);
        if ($capture->stage !== 'READY_FOR_PUBLICATION') return $this->blocked('CAPTURE_NOT_READY_FOR_PUBLICATION', ['capture' => $capture->toArray()]);
        if ($capture->articleId !== $articleId) return $this->blocked('CAPTURE_ARTICLE_BINDING_INVALID', ['capture_article_id' => $capture->articleId, 'article_id' => $articleId]);

        $state = $this->articles->read($articleId);
        if (!$state instanceof EditorialPostState) return $this->blocked('WP_POST_UNAVAILABLE', ['article_id' => $articleId]);
        $expectedToken = trim((string) ($input['expected_state_token'] ?? ''));
        if ($expectedToken !== '' && !hash_equals($expectedToken, $state->token)) return $this->blocked('EDITORIAL_CAS_REQUIRED', ['current_state_token' => $state->token]);
        $expectedToken = $state->token;

        $evidence = is_array($input['evidence'] ?? null) ? $input['evidence'] : [];
        $evidenceError = $this->validateEvidence($evidence, $capture, $articleId, $expectedToken);
        if ($evidenceError !== null) return $this->blocked($evidenceError['code'], $evidenceError['details']);

        $arguments = ['post_id' => $articleId, 'expected_state_token' => $expectedToken, 'idempotency_key' => $idempotencyKey, 'evidence' => $evidence];
        if ($operation === 'run') return $this->run($arguments, $input, $capture, $state);
        $tool = match ($operation) { 'review' => 'nhk.article.publish.review', 'approve' => 'nhk.article.publish.approve', default => 'nhk.article.publish' };
        if ($operation === 'approve') {
            $affirmation = trim((string) ($input['affirmation'] ?? ''));
            $decisionId = trim((string) ($input['decision_id'] ?? ''));
            if ($affirmation === '') return $this->blocked('OWNER_AFFIRMATION_REQUIRED');
            if ($decisionId === '') return $this->blocked('OWNER_DECISION_REQUIRED');
            $arguments += ['decision_id' => $decisionId, 'affirmation' => $affirmation];
        }
        return $this->finish(($this->invokeAbility)($tool, $arguments), $operation, $capture, $state);
    }

    /** @param array<string,mixed> $arguments @param array<string,mixed> $input */
    private function run(array $arguments, array $input, CaptureRecord $capture, EditorialPostState $state): array
    {
        $review = ($this->invokeAbility)('nhk.article.publish.review', $arguments);
        $outcome = (string) ($review['outcome'] ?? 'SYSTEM_BLOCKED');
        if ($outcome === 'SYSTEM_BLOCKED') return $this->finish($review, 'review', $capture, $state);
        if ($outcome === 'OWNER_REVIEW_REQUIRED') {
            $affirmation = trim((string) ($input['affirmation'] ?? ''));
            $decisionId = trim((string) ($input['decision_id'] ?? ($review['decision_id'] ?? '')));
            if ($affirmation === '') {
                $review['diagnostics'] = array_values(array_unique(array_merge((array) ($review['diagnostics'] ?? []), ['OWNER_AFFIRMATION_REQUIRED'])));
                return $review;
            }
            if ($decisionId === '') return $this->blocked('OWNER_DECISION_REQUIRED', ['review' => $review]);
            $approved = ($this->invokeAbility)('nhk.article.publish.approve', $arguments + ['decision_id' => $decisionId, 'affirmation' => $affirmation]);
            return $this->finish($approved, 'approve', $capture, $state);
        }
        if ($outcome !== 'PASS') return $this->finish($review, 'review', $capture, $state);
        return $this->finish(($this->invokeAbility)('nhk.article.publish', $arguments), 'publish', $capture, $state);
    }

    /** @param array<string,mixed> $result */
    private function finish(array $result, string $operation, CaptureRecord $capture, EditorialPostState $state): array
    {
        $result['capture_id'] = $capture->captureId;
        $result['article_id'] = $state->postId;
        $result['native_read_back'] = $this->safePostReadBack($result);
        $finalCapture = $this->captures->findById($capture->captureId);
        $result['capture_final_state'] = $finalCapture instanceof CaptureRecord ? $finalCapture->toArray() : ['status' => 'unavailable'];
        if (!in_array($operation, ['approve', 'publish'], true) || (string) ($result['outcome'] ?? '') !== 'PASS') return $result;
        if (!$finalCapture instanceof CaptureRecord) return $result + ['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['CAPTURE_READBACK_UNAVAILABLE'], 'root_cause' => 'CAPTURE_READBACK_UNAVAILABLE'];
        if ((string) ($result['publication_receipt']['outcome'] ?? '') !== 'COMPLETED') return $result + ['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['PUBLICATION_RECEIPT_UNAVAILABLE'], 'root_cause' => 'PUBLICATION_RECEIPT_UNAVAILABLE'];
        $permalink = trim((string) ($result['public_url'] ?? $result['post']['permalink'] ?? ''));
        if ($permalink === '' || $this->publicReadBack === null) return $result + ['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['PUBLIC_RENDERED_VERIFICATION_UNAVAILABLE'], 'root_cause' => 'PUBLIC_RENDERED_VERIFICATION_UNAVAILABLE'];
        $public = ($this->publicReadBack)($permalink);
        if (($public['status'] ?? '') !== 'verified') return $result + ['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['PUBLIC_RENDERED_VERIFICATION_FAILED'], 'public_rendered_readback' => $public, 'root_cause' => 'PUBLIC_RENDERED_VERIFICATION_FAILED'];
        return $result + ['public_rendered_readback' => $public];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function safePostReadBack(array $result): array
    {
        $post = is_array($result['post'] ?? null) ? $result['post'] : [];
        foreach (['content', 'body', 'post_content'] as $field) unset($post[$field]);
        return $post === [] ? ['status' => 'unavailable'] : ['status' => 'verified', 'post' => $post, 'state_token' => $result['state_token'] ?? null];
    }

    /** @param array<string,mixed> $evidence @return array{code:string,details:array<string,mixed>}|null */
    private function validateEvidence(array $evidence, CaptureRecord $capture, int $articleId, string $token): ?array
    {
        $required = ['capture_id', 'article_id', 'editorial_state_token', 'semantic_revision', 'documentation_checkpoint', 'current_publication_review', 'claim_compliance', 'seo_projection', 'structured_data', 'public_identity', 'media_usage', 'governance'];
        foreach ($required as $field) if (!array_key_exists($field, $evidence)) return ['code' => 'PUBLICATION_EVIDENCE_REQUIRED', 'details' => ['missing' => $field]];
        if ((string) $evidence['capture_id'] !== $capture->captureId || (int) $evidence['article_id'] !== $articleId || !hash_equals($token, (string) $evidence['editorial_state_token'])) return ['code' => 'PUBLICATION_EVIDENCE_BINDING_INVALID', 'details' => []];
        $active = ($this->documentationCheckpoint)();
        $checkpoint = is_array($evidence['documentation_checkpoint']) ? $evidence['documentation_checkpoint'] : [];
        if (!hash_equals($active['documentation_version'], (string) ($checkpoint['documentation_version'] ?? '')) || !hash_equals($active['manifest_hash'], (string) ($checkpoint['manifest_hash'] ?? ''))) return ['code' => 'DOCUMENTATION_CHECKPOINT_STALE', 'details' => ['active' => $active, 'provided' => $checkpoint]];
        $stored = is_array($capture->context['documentation_checkpoint'] ?? null) ? $capture->context['documentation_checkpoint'] : [];
        if ($stored !== [] && ((string) ($stored['documentation_version'] ?? '') !== $active['documentation_version'] || (string) ($stored['manifest_hash'] ?? '') !== $active['manifest_hash'])) return ['code' => 'DOCUMENTATION_CHECKPOINT_STALE', 'details' => ['active' => $active, 'capture' => $stored]];
        return null;
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    private function blocked(string $code, array $details = []): array { return ['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => [$code], 'root_cause' => $code] + ($details === [] ? [] : ['details' => $details]); }
}
