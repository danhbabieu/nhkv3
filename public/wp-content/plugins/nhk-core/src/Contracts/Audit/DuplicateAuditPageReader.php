<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Audit;

/**
 * Read-only, bounded page boundary for duplicate audits.
 *
 * Implementations must order pages by a stable identity and must not mutate
 * the owner while reading. A missing implementation is an audit model gap,
 * never permission to call an unbounded list() method.
 */
interface DuplicateAuditPageReader
{
    /** @return array{items:list<mixed>,next_cursor:?string,diagnostics?:list<array<string,mixed>>} */
    public function page(?string $after, int $limit): array;
}
