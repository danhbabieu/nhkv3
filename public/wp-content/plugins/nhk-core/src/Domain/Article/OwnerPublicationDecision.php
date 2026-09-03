<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

use DateTimeImmutable;

final readonly class OwnerPublicationDecision
{
    public function __construct(
        public string $decisionId,
        public string $idempotencyKey,
        public string $requestFingerprint,
        public int $wpPostId,
        public string $decision,
        public ArticlePublicationOutcome $gateOutcome,
        public array $diagnostics,
        public array $overriddenDiagnosticCodes,
        public string $blockerFingerprint,
        public string $editorialStateToken,
        public string $policyVersion,
        public string $principalId,
        public array $approvalProvenance,
        public string $approvedAt,
        public string $expiresAt,
        public string $stage = 'APPROVAL_RECORDED',
        public array $publishAttempt = [],
        public array $readback = [],
        public string $finalOutcome = '',
        public int $revision = 1,
    ) {
        if ($decisionId === '' || $idempotencyKey === '' || !preg_match('/^[a-f0-9]{64}$/i', $requestFingerprint) || $wpPostId < 1 || $editorialStateToken === '' || $policyVersion === '' || $principalId === '' || $revision < 1) throw new \InvalidArgumentException('Owner publication decision identity is invalid.');
        if (!in_array($decision, ['APPROVED_WITH_EXCEPTIONS', 'DENIED'], true)) throw new \InvalidArgumentException('Owner publication decision is invalid.');
        if (strtotime($expiresAt) === false || strtotime($approvedAt) === false) throw new \InvalidArgumentException('Owner publication timestamps are invalid.');
    }

    public static function approved(string $decisionId, string $idempotencyKey, int $postId, string $token, string $blockerFingerprint, string $principalId, string $approvedAt): self
    {
        $expires = (new DateTimeImmutable($approvedAt))->modify('+30 minutes')->format(DATE_ATOM);
        return new self($decisionId, $idempotencyKey, hash('sha256', $postId . '|' . $token . '|' . $blockerFingerprint), $postId, 'APPROVED_WITH_EXCEPTIONS', ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED, [], [], $blockerFingerprint, $token, PublicationDiagnosticRegistry::POLICY_VERSION, $principalId, [], $approvedAt, $expires);
    }

    public function isExpired(DateTimeImmutable $now): bool { return $now >= new DateTimeImmutable($this->expiresAt); }

    public function bindingFingerprint(): string { return hash('sha256', $this->wpPostId . '|' . $this->editorialStateToken . '|' . $this->policyVersion . '|' . $this->blockerFingerprint . '|' . $this->principalId); }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['decision_id' => $this->decisionId, 'idempotency_key' => $this->idempotencyKey, 'request_fingerprint' => $this->requestFingerprint, 'wp_post_id' => $this->wpPostId, 'decision' => $this->decision, 'gate_outcome' => $this->gateOutcome->value, 'diagnostics' => $this->diagnostics, 'overridden_diagnostic_codes' => $this->overriddenDiagnosticCodes, 'blocker_fingerprint' => $this->blockerFingerprint, 'editorial_state_token' => $this->editorialStateToken, 'policy_version' => $this->policyVersion, 'principal_id' => $this->principalId, 'approval_provenance' => $this->approvalProvenance, 'approved_at' => $this->approvedAt, 'expires_at' => $this->expiresAt, 'stage' => $this->stage, 'publish_attempt' => $this->publishAttempt, 'readback' => $this->readback, 'final_outcome' => $this->finalOutcome, 'revision' => $this->revision];
    }
}
