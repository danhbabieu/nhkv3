<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Governance\ControlledApplyService;
use NHK\Core\Application\Governance\ProposalEligibilityService;
use NHK\Core\Domain\Governance\Proposal;

final class McpGovernanceHandler
{
    public function __construct(private GovernanceService $governance, private ?ProposalEligibilityService $eligibility = null, private ?ControlledApplyService $apply = null) {}

    public function create(Proposal $proposal): Proposal { return $this->governance->create($proposal); }
    public function submit(string $id): Proposal { return $this->governance->submit($id); }
    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { return $this->governance->approve($id, $contentFingerprint, $dependencyFingerprint, $actor); }
    public function reject(string $id, string $actor): Proposal { return $this->governance->reject($id, $actor); }
    public function eligibility(string $id): array
    {
        if (!$this->eligibility) throw new \RuntimeException('Eligibility service is not configured.');
        $result = $this->eligibility->check($id);
        return ['proposal_id' => $id, 'ready' => $result->ready, 'reasons' => $result->reasons];
    }
    public function apply(string $id): array
    {
        if (!$this->apply) throw new \RuntimeException('Controlled Apply service is not configured.');
        return $this->apply->apply($id);
    }
}
