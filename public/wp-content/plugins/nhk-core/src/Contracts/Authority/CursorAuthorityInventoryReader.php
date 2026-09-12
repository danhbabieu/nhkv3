<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Authority;

use NHK\Core\Domain\Authority\AuthorityEntity;

/** Read-only, stable-cursor inventory boundary for bounded audits. */
interface CursorAuthorityInventoryReader
{
    /** @return array{items:list<AuthorityEntity>,next_cursor:?string} */
    public function pageByType(string $type, int $limit = 100, ?string $after = null, bool $includeRetired = false): array;
}
