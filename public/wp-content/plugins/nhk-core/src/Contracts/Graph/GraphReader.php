<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Graph;

use NHK\Core\Domain\Graph\NodeReference;

/** Read-only graph boundary used by analysis and projections. */
interface GraphReader
{
    /** @return array{items:list<mixed>,next_cursor:int|null} */
    public function findOutgoing(NodeReference $source, ?string $predicate = null, int $after = 0, int $limit = 50, bool $includeRetired = false, ?string $targetType = null): array;
}
