<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Audit;

/**
 * Read-only adapter for an approved evidence surface used by the legacy
 * Clock-Type audit. Implementations must return safe references only; raw
 * private excerpts and source payloads do not belong in an audit report.
 */
interface ClockTypeAuditEvidenceReader
{
    /** @return list<array<string,mixed>> */
    public function findForSubject(string $sourceType, string $sourceUuid): array;
}
