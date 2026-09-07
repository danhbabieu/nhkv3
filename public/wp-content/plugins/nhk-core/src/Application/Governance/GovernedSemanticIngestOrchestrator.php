<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\GovernanceAuditSink;
use NHK\Core\Contracts\Governance\GovernedLifecycle;
use NHK\Core\Domain\Governance\AutomationMode;

final class GovernedSemanticIngestOrchestrator
{
    /** @param callable(array<string,mixed>):bool $approvalPolicy
     *  @param callable(string):array<string,mixed> $apply */
    public function __construct(
        private GovernedLifecycle $governance,
        private $approvalPolicy,
        private $apply,
        private ?GovernanceAutomationPolicyResolver $policyResolver = null,
        private $projection = null,
        private ?GovernanceAuditSink $audit = null,
    ) {}

    /** @param list<array<string,mixed>> $nodes @return list<array<string,mixed>> */
    public function run(array $nodes): array
    {
        $results = [];
        $verified = [];
        foreach ($nodes as $node) {
            foreach ((array) ($node['dependency_ids'] ?? []) as $dependencyId) {
                if (!isset($verified[(string) $dependencyId])) throw new \RuntimeException('DEPENDENCY_NOT_VERIFIED');
            }
            $proposal = $this->governance->createFromArguments($node);
            $this->governance->submit($proposal->id);
            $review = $this->governance->review($proposal->id);
            $mode = $this->policyResolver?->resolve((string) ($node['entity_type'] ?? ''));
            if (($review['state'] ?? '') !== 'submitted') {
                if ($mode !== null) {
                    $results[] = $this->blocked($proposal->id, $mode, 'review', ['REVIEW_STATE_INVALID'], (string) ($review['state'] ?? ''));
                    continue;
                }
                throw new \RuntimeException('MANUAL_APPROVAL_REQUIRED');
            }
            if ($mode === AutomationMode::REVIEW_REQUIRED || ($mode === null && !(bool) ($this->approvalPolicy)($review))) {
                if ($mode !== null) {
                    $results[] = $this->result($proposal->id, $mode, 'awaiting_review', 'review', 'submitted');
                    continue;
                }
                throw new \RuntimeException('MANUAL_APPROVAL_REQUIRED');
            }
            $approved = $this->governance->approve($proposal->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, $mode !== null ? '0' : 'orchestrator');
            $this->audit?->recordEvent('GovernanceAutomationApproved', 'proposal', $approved->id, 0, ['actor_kind' => 'system', 'mode' => $mode?->value ?? 'legacy']);
            $eligibility = $this->governance->eligibility($approved->id);
            if (!($eligibility['ready'] ?? false)) {
                if ($mode !== null) {
                    $results[] = $this->blocked($approved->id, $mode, 'eligibility', array_values(array_map('strval', (array) ($eligibility['reasons'] ?? ['DEPENDENCY_NOT_ELIGIBLE']))), 'approved');
                    continue;
                }
                throw new \RuntimeException('DEPENDENCY_NOT_ELIGIBLE');
            }
            if ($mode === AutomationMode::AUTO_APPROVE) {
                $results[] = $this->result($approved->id, $mode, 'ready_to_apply', 'eligibility', 'approved');
                continue;
            }
            try {
                $applied = ($this->apply)($approved->id);
            } catch (\Throwable $error) {
                if ($mode !== null) {
                    $results[] = $this->blocked($approved->id, $mode, 'apply', [$error->getMessage()], 'approved');
                    continue;
                }
                throw $error;
            }
            if (!is_array($applied['canonical_readback'] ?? null)) {
                if ($mode !== null) {
                    $results[] = $this->blocked($approved->id, $mode, 'canonical_readback', ['CANONICAL_READBACK_VERIFICATION_FAILED'], 'approved');
                    continue;
                }
                throw new \RuntimeException('CANONICAL_READBACK_VERIFICATION_FAILED');
            }
            $this->audit?->recordEvent('GovernanceAutomationApplied', 'proposal', $approved->id, 0, ['actor_kind' => 'system', 'mode' => $mode?->value ?? 'legacy']);
            if ($mode === AutomationMode::AUTO_PUBLISH) {
                if ($this->projection === null) {
                    $results[] = $this->blocked($approved->id, $mode, 'projection', ['PROJECTION_VERIFIER_UNAVAILABLE'], 'applied', $applied);
                    continue;
                }
                try {
                    $frontend = ($this->projection)($applied);
                } catch (\Throwable $error) {
                    $results[] = $this->blocked($approved->id, $mode, 'projection', [$error->getMessage()], 'applied', $applied);
                    continue;
                }
                if (!is_array($frontend) || ($frontend['frontend_available'] ?? false) !== true) {
                    $results[] = $this->blocked($approved->id, $mode, 'frontend', ['FRONTEND_NOT_AVAILABLE'], 'applied', $applied, is_array($frontend) ? $frontend : []);
                    continue;
                }
                $this->audit?->recordEvent('GovernanceAutomationPublished', 'proposal', $approved->id, 0, ['actor_kind' => 'system', 'mode' => $mode->value]);
                $results[] = $this->result($approved->id, $mode, 'published', 'frontend', 'applied', $applied, $frontend);
                continue;
            }
            $verified[$approved->id] = true;
            $results[] = $this->result($approved->id, $mode, 'applied', 'canonical_readback', 'applied', $applied);
        }
        return $results;
    }

    /** @return array<string,mixed> */
    private function result(string $proposalId, ?AutomationMode $mode, string $status, string $gate, string $state, array $apply = [], array $frontend = []): array
    {
        $result = ['status' => $status, 'mode' => $mode?->value ?? 'LEGACY', 'gate_reached' => $gate, 'proposal_id' => $proposalId, 'proposal_state' => $state, 'blockers' => []];
        if ($apply !== []) $result += ['canonical_id' => $apply['canonical_readback']['canonical_id'] ?? null, 'canonical_readback' => $apply['canonical_readback'], 'apply' => $apply];
        if ($frontend !== []) $result['frontend'] = $frontend;
        return $result;
    }

    /** @return array<string,mixed> */
    private function blocked(string $proposalId, AutomationMode $mode, string $gate, array $blockers, string $state, array $apply = [], array $frontend = []): array
    {
        $result = $this->result($proposalId, $mode, 'blocked', $gate, $state, $apply, $frontend);
        $result['blockers'] = $blockers;
        return $result;
    }
}
