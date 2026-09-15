<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Governance\{GovernanceAutomationPolicyResolver, GovernanceService};
use NHK\Core\Application\Graph\RelationRevisionBinder;
use NHK\Core\Contracts\Governance\GovernedLifecycle;
use NHK\Core\Domain\Graph\EndpointTypeRegistry;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal, ProposalState};

/**
 * Rebuilds a stale governed relation command from current endpoint revisions.
 * The original command is never edited and is superseded only after the
 * replacement has passed the complete governed lifecycle and read-back.
 */
final class RelationProposalReconciliationService
{
    public function __construct(
        private GovernedLifecycle $lifecycle,
        private GovernanceService $governance,
        private EndpointTypeRegistry $endpoints,
        private $apply,
        private GovernanceAutomationPolicyResolver $policies,
        private $can,
        private $actor = null,
    ) {}

    /** @return array<string,mixed> */
    public function reconcile(Proposal $original, array $control = []): array
    {
        if ($original->entityType !== 'relation' || $original->operation !== 'relation_create') return $this->blocked($original, 'RELATION_RECONCILIATION_UNSUPPORTED');
        if (!in_array($original->state, [ProposalState::SUBMITTED, ProposalState::APPROVED], true)) return $this->blocked($original, 'RELATION_PROPOSAL_NOT_RESUMABLE');

        try {
            $payload = (new RelationRevisionBinder($this->endpoints))->bind($original->payload);
            $key = 'relation-reconcile:' . $original->id . ':' . hash('sha256', CommandCanonicalizer::canonicalize($payload));
            $replacement = $this->lifecycle->createFromArguments([
                'operation' => 'relation_create',
                'entity_type' => 'relation',
                'subject_id' => (string) ($payload['source_uuid'] ?? $original->subjectId),
                'payload' => $payload,
                'idempotency_key' => $key,
            ]);
            $result = $this->run($replacement, $control);
            if (($result['status'] ?? '') !== 'APPLIED') return $result + ['replaced_proposal_id' => $replacement->id, 'original_proposal_id' => $original->id];
            $this->governance->supersede($original->id, $replacement->id, $this->actor());
            return $result + ['replaced_proposal_id' => $original->id, 'original_proposal_id' => $original->id];
        } catch (\Throwable $error) {
            return $this->blocked($original, $this->errorCode($error), $error->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function run(Proposal $proposal, array $control): array
    {
        $review = $this->lifecycle->review($proposal->id);
        $state = (string) ($review['state'] ?? $proposal->state->value);
        if ($state === ProposalState::DRAFT->value) {
            $proposal = $this->lifecycle->submit($proposal->id);
            $review = $this->lifecycle->review($proposal->id);
            $state = (string) ($review['state'] ?? $proposal->state->value);
        }
        if ($state === ProposalState::SUBMITTED->value) {
            if (($this->policies->resolve('relation')->value ?? '') === 'REVIEW_REQUIRED' && !(bool) ($control['approval_confirmed'] ?? false)) return ['status' => 'REVIEW_REQUIRED', 'proposal_id' => $proposal->id, 'blockers' => ['GOVERNANCE_APPROVAL_REQUIRED']];
            if (!(($this->can)('nhk_internal_content_operations'))) return ['status' => 'SYSTEM_BLOCKED', 'proposal_id' => $proposal->id, 'blockers' => ['INTERNAL_CAPABILITY_REQUIRED_FOR_CAPTURE_GOVERNANCE_APPROVAL']];
            $proposal = $this->lifecycle->approve($proposal->id, (string) ($review['content_fingerprint'] ?? $proposal->contentFingerprint), (string) ($review['dependency_fingerprint'] ?? $proposal->dependencyFingerprint), $this->actor());
        }
        $eligibility = $this->lifecycle->eligibility($proposal->id);
        if (($eligibility['ready'] ?? false) !== true) return ['status' => 'SYSTEM_BLOCKED', 'proposal_id' => $proposal->id, 'blockers' => array_values(array_map('strval', (array) ($eligibility['reasons'] ?? ['PROPOSAL_NOT_ELIGIBLE'])))];
        $applied = ($this->apply)($proposal->id);
        $readback = is_array($applied['canonical_readback'] ?? null) ? $applied['canonical_readback'] : [];
        if (trim((string) ($readback['canonical_id'] ?? '')) === '' || ($readback['active'] ?? false) !== true) return ['status' => 'SYSTEM_BLOCKED', 'proposal_id' => $proposal->id, 'blockers' => ['CANONICAL_READBACK_VERIFICATION_FAILED']];
        return ['status' => 'APPLIED', 'proposal_id' => $proposal->id, 'canonical_id' => $applied['canonical_id'] ?? $readback['canonical_id'], 'canonical_readback' => $readback, 'idempotent' => (bool) ($applied['idempotent'] ?? false)];
    }

    /** @return array<string,mixed> */
    private function blocked(Proposal $proposal, string $reason, string $message = ''): array
    {
        return ['status' => 'SYSTEM_BLOCKED', 'proposal_id' => $proposal->id, 'blockers' => [$reason]] + ($message !== '' ? ['error' => $message] : []);
    }

    private function actor(): string { return $this->actor !== null ? (string) (($this->actor)()) : 'capture-reconciler'; }

    private function errorCode(\Throwable $error): string
    {
        $code = strtoupper(trim((string) $error->getCode()));
        return preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $code) === 1 ? $code : 'RELATION_RECONCILIATION_FAILED';
    }
}
