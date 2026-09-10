<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

use NHK\Core\Domain\Governance\{EligibilityResult, Proposal};

interface GovernanceActionPort
{
    public function find(string $id): ?Proposal;
    public function submit(string $id): Proposal;
    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal;
    public function reject(string $id, string $actor): Proposal;
    public function eligibility(string $id): EligibilityResult;
    /** @return array<string, mixed> */
    public function apply(string $id): array;
}
