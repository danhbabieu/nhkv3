<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Application\Governance\{ControlledApplyService, GovernanceService, ProposalEligibilityService};
use NHK\Core\Contracts\Governance\ProposalRepository;

final class GovernanceRuntime
{
    public function __construct(
        public readonly ProposalRepository $proposals,
        public readonly GovernanceService $governance,
        public readonly ProposalEligibilityService $eligibility,
        public readonly ControlledApplyService $controlledApply,
    ) {}
}
