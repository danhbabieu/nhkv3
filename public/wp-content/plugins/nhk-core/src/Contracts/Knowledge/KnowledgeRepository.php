<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Knowledge;

use NHK\Core\Domain\Knowledge\KnowledgeClaim;

interface KnowledgeRepository
{
    public function findByCanonicalId(string $id): ?KnowledgeClaim;
    public function findByStableKey(string $stableKey): ?KnowledgeClaim;
    public function create(KnowledgeClaim $claim): KnowledgeClaim;
    public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim;
    /** @return list<KnowledgeClaim> */
    public function list(bool $includeRetired = false): array;
}
