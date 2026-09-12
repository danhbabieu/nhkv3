<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Authority;

/** Read-only, stable-cursor inventory boundary for bounded audits. */
interface AuthorityInventoryReader
{
    /** @return array{items:list<\NHK\Core\Domain\Authority\AuthorityEntity>,next_cursor:?string} */
    public function pageByType(string $type, int $limit = 100, ?string $after = null, bool $includeRetired = false): array;
}
