<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\{EligibilityReader, ProposalRepository};
use NHK\Core\Contracts\Graph\GraphRepository;

final class WpdbEligibilityReader implements EligibilityReader
{
    public function __construct(private AuthorityRepository $authority, private ProposalRepository $proposals, private ?GraphRepository $graph = null) {}

    public function isApplied(string $dependencyUuid): bool
    {
        return $this->proposals->find($dependencyUuid)?->state->value === 'applied';
    }

    public function targetRevision(string $targetUuid): ?int
    {
        return $this->authority->findByCanonicalId($targetUuid)?->revision ?? $this->graph?->findByUuid($targetUuid)?->revision;
    }

    public function targetExists(string $targetUuid): bool
    {
        return $this->authority->findByCanonicalId($targetUuid) !== null || $this->graph?->findByUuid($targetUuid) !== null;
    }
}
