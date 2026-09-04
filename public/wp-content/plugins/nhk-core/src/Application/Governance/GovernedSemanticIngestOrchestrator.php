<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\GovernedLifecycle;

final class GovernedSemanticIngestOrchestrator
{
    /** @param callable(array<string,mixed>):bool $approvalPolicy
     *  @param callable(string):array<string,mixed> $apply */
    public function __construct(private GovernedLifecycle $governance, private $approvalPolicy, private $apply) {}

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
            if (($review['state'] ?? '') !== 'submitted' || !(bool) ($this->approvalPolicy)($review)) {
                throw new \RuntimeException('MANUAL_APPROVAL_REQUIRED');
            }
            $approved = $this->governance->approve($proposal->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, 'orchestrator');
            $eligibility = $this->governance->eligibility($approved->id);
            if (!($eligibility['ready'] ?? false)) throw new \RuntimeException('DEPENDENCY_NOT_ELIGIBLE');
            $applied = ($this->apply)($approved->id);
            if (!is_array($applied['canonical_readback'] ?? null)) throw new \RuntimeException('CANONICAL_READBACK_VERIFICATION_FAILED');
            $verified[$approved->id] = true;
            $results[] = ['proposal_id' => $approved->id, 'proposal_state' => 'applied', 'canonical_id' => $applied['canonical_readback']['canonical_id'], 'canonical_readback' => $applied['canonical_readback'], 'apply' => $applied];
        }
        return $results;
    }
}
