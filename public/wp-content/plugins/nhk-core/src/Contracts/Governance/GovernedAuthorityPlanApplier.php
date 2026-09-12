<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

use NHK\Core\Domain\Governance\ConversationalAuthorityPolicy;

/** Governed plan application seam; implementations own approval/apply rules. */
interface GovernedAuthorityPlanApplier
{
    /** @return array<string,mixed> */
    public function execute(array $plan, string $approvedFingerprint, string $currentFingerprint, array $approvedCandidateIds, ConversationalAuthorityPolicy $policy, string $actor = '0'): array;
}
