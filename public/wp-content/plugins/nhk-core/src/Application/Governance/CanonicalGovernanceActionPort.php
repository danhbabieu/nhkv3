<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\{GovernanceActionPort, ProposalRepository};
use NHK\Core\Domain\Governance\{EligibilityResult, Proposal};

final class CanonicalGovernanceActionPort implements GovernanceActionPort
{
    public function __construct(
        private ProposalRepository $proposals,
        private GovernanceService $governance,
        private ProposalEligibilityService $eligibilityService,
        private ControlledApplyService $controlledApply,
    ) {}

    public function find(string $id): ?Proposal { return $this->proposals->find($id); }
    public function submit(string $id): Proposal { return $this->governance->submit($id); }
    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { return $this->governance->approve($id, $contentFingerprint, $dependencyFingerprint, $actor); }
    public function reject(string $id, string $actor): Proposal { return $this->governance->reject($id, $actor); }
    public function eligibility(string $id): EligibilityResult { return $this->eligibilityService->check($id); }
    public function apply(string $id): array { return $this->controlledApply->apply($id); }
}
