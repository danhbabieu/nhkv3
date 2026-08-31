<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\{EligibilityReader, ProposalRepository};

final class WpdbEligibilityReader implements EligibilityReader
{
    public function __construct(private AuthorityRepository $authority, private ProposalRepository $proposals) {}

    public function isApplied(string $dependencyUuid): bool
    {
        return $this->proposals->find($dependencyUuid)?->state->value === 'applied';
    }

    public function targetRevision(string $targetUuid): ?int
    {
        return $this->authority->findByCanonicalId($targetUuid)?->revision;
    }

    public function targetExists(string $targetUuid): bool
    {
        return $this->authority->findByCanonicalId($targetUuid) !== null;
    }
}
