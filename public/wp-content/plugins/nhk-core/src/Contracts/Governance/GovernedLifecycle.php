<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

use NHK\Core\Domain\Governance\Proposal;

interface GovernedLifecycle
{
    public function createFromArguments(array $arguments): Proposal;
    public function submit(string $id): Proposal;
    /** @return array<string,mixed> */
    public function review(string $id): array;
    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal;
    /** @return array<string,mixed> */
    public function eligibility(string $id): array;
}
