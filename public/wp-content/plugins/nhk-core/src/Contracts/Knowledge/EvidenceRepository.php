<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Knowledge;

use NHK\Core\Domain\Knowledge\Evidence;

interface EvidenceRepository
{
    public function findByCanonicalId(string $id): ?Evidence;
    public function create(Evidence $evidence): Evidence;
    /** @return list<Evidence> */
    public function listByClaim(string $claimId, bool $includeRetired = false): array;
    /** @return list<Evidence> */
    public function listBySource(string $sourceId, bool $includeRetired = false): array;
}
