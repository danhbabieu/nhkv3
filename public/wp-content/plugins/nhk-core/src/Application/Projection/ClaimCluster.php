<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Domain\Projection\ProjectedClaim;

final readonly class ClaimCluster
{
    /** @param list<string> $supportingClaimIds @param list<string> $contradictoryClaimIds */
    public function __construct(public string $semanticKey, public string $representativeClaimId, public array $supportingClaimIds, public array $contradictoryClaimIds, public ProjectedClaim $representativeClaim) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['semantic_key' => $this->semanticKey, 'representative_claim_id' => $this->representativeClaimId, 'supporting_claim_ids' => $this->supportingClaimIds, 'contradictory_claim_ids' => $this->contradictoryClaimIds];
    }
}
