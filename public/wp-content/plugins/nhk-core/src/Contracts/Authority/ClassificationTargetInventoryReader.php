<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Authority;

/** Read-only classification inventory boundary for target bucketing. */
interface ClassificationTargetInventoryReader
{
    /** @return array{items:list<\NHK\Core\Domain\Authority\AuthorityEntity>,next_cursor:?string} */
    public function pageClassifications(int $limit = 100, ?string $after = null, bool $includeRetired = false): array;
}
