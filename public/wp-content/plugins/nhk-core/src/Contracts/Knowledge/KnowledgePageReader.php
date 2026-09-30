<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Knowledge;

use NHK\Core\Domain\Knowledge\KnowledgeClaim;

/** Optional bounded reader used by read-only corpus audits. */
interface KnowledgePageReader
{
    /** @return array{items:list<KnowledgeClaim>,has_more:bool,next_cursor?:?string,diagnostics?:list<array<string,mixed>>} */
    public function page(bool $includeRetired, ?string $afterStableKey, int $limit): array;
}
