<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Authority;

use NHK\Core\Domain\Authority\AuthorityEntity;

/** Read-only canonical lookup used after a governed Authority apply. */
interface AuthorityCanonicalReader
{
    public function findByCanonicalId(string $id): ?AuthorityEntity;
    public function findByStableKey(string $type, string $key): ?AuthorityEntity;
    /** @return list<AuthorityEntity> */
    public function listByType(string $type, bool $includeRetired = false): array;
}
