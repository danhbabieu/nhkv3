<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Knowledge;

use NHK\Core\Domain\Knowledge\Source;

interface SourceRepository
{
    public function findByCanonicalId(string $id): ?Source;
    public function findByStableKey(string $stableKey): ?Source;
    public function create(Source $source): Source;
    public function update(Source $source, int $expectedRevision): Source;
    /** @return list<Source> */
    public function list(bool $includeRetired = false): array;
}
