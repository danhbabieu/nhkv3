<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use DateTimeImmutable;
use NHK\Core\Contracts\Article\{OwnerPublicationDecisionRepository, OwnerPublicationService, PublicationPrincipal};
use NHK\Core\Contracts\WordPress\EditorialPostStore;
use NHK\Core\Domain\Article\{ArticlePublicationOutcome, OwnerPublicationDecision, PublicationDiagnosticRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

final class OwnerPublicationApplicationService implements OwnerPublicationService
{
    /** @param callable|null $can @param callable|null $clock */
    public function __construct(private EditorialPostStore $posts, private OwnerPublicationDecisionRepository $decisions, private $can = null, private $clock = null) {}

    public function request(int $postId, string $expectedStateToken, array $evidence, string $idempotencyKey, PublicationPrincipal $principal): array
    {
        if ($this->can !== null && !(bool) ($this->can)($principal)) return $this->blocked('PUBLICATION_AUTHORIZATION_FAILED');
        $state = $this->posts->read($postId);
        if ($state === null) return $this->blocked('WP_POST_UNAVAILABLE');
        if (!hash_equals($expectedStateToken, $state->token)) return ['outcome' => ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED->value, 'diagnostics' => ['EDITORIAL_CAS_REQUIRED'], 'policy_version' => PublicationDiagnosticRegistry::policyVersion(), 'blocker_fingerprint' => PublicationDiagnosticRegistry::fingerprint(['EDITORIAL_CAS_REQUIRED']), 'confirmation_question' => 'Bài đã thay đổi hoặc cần Owner review mới. Vẫn đăng không?'];
        $gate = (new ArticlePublicationGate())->check($state, $evidence, $expectedStateToken);
        $base = ['outcome' => $gate->outcome()->value, 'diagnostics' => $gate->blockers, 'warnings' => $gate->warnings, 'policy_version' => PublicationDiagnosticRegistry::policyVersion(), 'blocker_fingerprint' => $gate->blockerFingerprint()];
        if ($gate->outcome() === ArticlePublicationOutcome::SYSTEM_BLOCKED) return $base + ['root_cause' => $gate->blockers[0] ?? 'SYSTEM_BLOCKED'];
        if ($gate->outcome() === ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED) {
            $decisionId = UuidCodec::newV7(); $approvedAt = $this->now()->format(DATE_ATOM); $decision = new OwnerPublicationDecision($decisionId, $idempotencyKey . ':review', hash('sha256', $postId . '|' . $expectedStateToken . '|' . $gate->blockerFingerprint() . '|' . $principal->id), $postId, 'DENIED', $gate->outcome(), $gate->blockers, $gate->blockers, $gate->blockerFingerprint(), $state->token, PublicationDiagnosticRegistry::policyVersion(), $principal->id, ['channel' => $principal->channel, 'request_reference' => $principal->requestReference], $approvedAt, $this->now()->modify('+30 minutes')->format(DATE_ATOM));
            $stored = $this->decisions->create($decision);
            return $base + ['decision_id' => $stored->decisionId, 'expires_at' => $stored->expiresAt, 'confirmation_question' => 'Bài còn ' . count($gate->blockers) . ' điểm chưa đạt. Vẫn đăng không?'];
        }
        return $this->publish($state, $evidence, $idempotencyKey, $base);
    }

    public function approveAndPublish(int $postId, string $expectedStateToken, array $evidence, string $idempotencyKey, string $decisionId, PublicationPrincipal $principal, string $affirmation): array
    {
        if (!in_array(trim($affirmation), ['Đăng.', 'Vẫn đăng.', 'Publish.'], true)) return $this->blocked('OWNER_AFFIRMATION_REQUIRED');
        if ($this->can !== null && !(bool) ($this->can)($principal)) return $this->blocked('PUBLICATION_AUTHORIZATION_FAILED');
        $state = $this->posts->read($postId); if ($state === null) return $this->blocked('WP_POST_UNAVAILABLE');
        if (!hash_equals($expectedStateToken, $state->token)) return ['outcome' => ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED->value, 'diagnostics' => ['EDITORIAL_CAS_REQUIRED'], 'policy_version' => PublicationDiagnosticRegistry::policyVersion(), 'blocker_fingerprint' => PublicationDiagnosticRegistry::fingerprint(['EDITORIAL_CAS_REQUIRED']), 'confirmation_question' => 'Bài đã thay đổi hoặc cần Owner review mới. Vẫn đăng không?'];
        $gate = (new ArticlePublicationGate())->check($state, $evidence, $expectedStateToken);
        if ($gate->outcome() !== ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED) return ['outcome' => $gate->outcome()->value, 'diagnostics' => $gate->blockers, 'warnings' => $gate->warnings, 'policy_version' => PublicationDiagnosticRegistry::policyVersion(), 'blocker_fingerprint' => $gate->blockerFingerprint()];
        $decision = $this->decisions->findByIdempotencyKey($idempotencyKey . ':review');
        if ($decision === null || !hash_equals($decision->decisionId, $decisionId) || $decision->wpPostId !== $postId || !hash_equals($decision->editorialStateToken, $state->token) || !hash_equals($decision->blockerFingerprint, $gate->blockerFingerprint()) || $decision->policyVersion !== PublicationDiagnosticRegistry::policyVersion() || $decision->principalId !== $principal->id || $decision->isExpired($this->now())) return ['outcome' => ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED->value, 'diagnostics' => $gate->blockers, 'policy_version' => PublicationDiagnosticRegistry::policyVersion(), 'blocker_fingerprint' => $gate->blockerFingerprint(), 'confirmation_question' => 'Bài đã thay đổi hoặc cần Owner review mới. Vẫn đăng không?'];
        $approved = new OwnerPublicationDecision(UuidCodec::newV7(), $idempotencyKey . ':approved', $decision->requestFingerprint, $decision->wpPostId, 'APPROVED_WITH_EXCEPTIONS', $decision->gateOutcome, $gate->blockers, $gate->blockers, $decision->blockerFingerprint, $decision->editorialStateToken, $decision->policyVersion, $decision->principalId, $decision->approvalProvenance + ['affirmation' => trim($affirmation)], $decision->approvedAt, $decision->expiresAt, 'APPROVAL_RECORDED', ['status' => 'recorded']);
        $this->decisions->append($approved);
        $this->decisions->append(new OwnerPublicationDecision(UuidCodec::newV7(), $idempotencyKey . ':attempted', $approved->requestFingerprint, $approved->wpPostId, $approved->decision, $approved->gateOutcome, $approved->diagnostics, $approved->overriddenDiagnosticCodes, $approved->blockerFingerprint, $approved->editorialStateToken, $approved->policyVersion, $approved->principalId, $approved->approvalProvenance, $approved->approvedAt, $approved->expiresAt, 'PUBLISH_ATTEMPTED', ['status' => 'started']));
        $result = $this->publish($state, $evidence, $idempotencyKey . ':publish', ['outcome' => ArticlePublicationOutcome::PASS->value, 'diagnostics' => $gate->blockers, 'warnings' => $gate->warnings, 'policy_version' => $decision->policyVersion, 'blocker_fingerprint' => $decision->blockerFingerprint, 'final_outcome' => 'published_with_exceptions', 'decision_id' => $decision->decisionId]);
        if (($result['post']['status'] ?? '') === 'publish') {
            $this->decisions->append(new OwnerPublicationDecision(UuidCodec::newV7(), $idempotencyKey . ':completed', $approved->requestFingerprint, $approved->wpPostId, $approved->decision, $approved->gateOutcome, $approved->diagnostics, $approved->overriddenDiagnosticCodes, $approved->blockerFingerprint, $approved->editorialStateToken, $approved->policyVersion, $approved->principalId, $approved->approvalProvenance, $approved->approvedAt, $approved->expiresAt, 'READBACK_VERIFIED', ['status' => 'publish'], $result['post'], 'published_with_exceptions'));
        }
        return $result;
    }

    private function publish(\NHK\Core\Domain\Article\EditorialPostState $state, array $evidence, string $key, array $result): array
    {
        try { $published = $this->posts->publish($state->postId); $readback = $this->posts->read($state->postId); if ($readback === null || $readback->status !== 'publish') return $this->blocked('PUBLICATION_RESULT_UNCERTAIN'); return $result + ['post' => $readback->snapshot(), 'state_token' => $readback->token, 'public_url' => $readback->permalink]; } catch (\Throwable) { $readback = $this->posts->read($state->postId); return $readback?->status === 'publish' ? $result + ['post' => $readback->snapshot(), 'state_token' => $readback->token, 'public_url' => $readback->permalink] : $this->blocked('PUBLICATION_RESULT_UNCERTAIN'); }
    }
    private function blocked(string $code): array { return ['outcome' => ArticlePublicationOutcome::SYSTEM_BLOCKED->value, 'diagnostics' => [$code], 'root_cause' => $code, 'policy_version' => PublicationDiagnosticRegistry::policyVersion(), 'blocker_fingerprint' => PublicationDiagnosticRegistry::fingerprint([$code])]; }
    private function now(): DateTimeImmutable { return $this->clock ? ($this->clock)() : new DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
