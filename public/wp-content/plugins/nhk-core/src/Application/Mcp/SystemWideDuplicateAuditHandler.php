<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Audit\SystemWideDuplicateAuditCoordinator;

/** Read-only MCP adapter for the bounded system-wide duplicate audit. */
final class SystemWideDuplicateAuditHandler
{
    public function __construct(private SystemWideDuplicateAuditCoordinator $coordinator) {}

    /** @return array<string,mixed> */
    public function audit(array $input): array
    {
        $owner = isset($input['owner']) && trim((string) $input['owner']) !== '' ? trim((string) $input['owner']) : null;
        $cursor = isset($input['cursor']) ? trim((string) $input['cursor']) : null;
        $result = $this->coordinator->audit(
            max(1, min(200, (int) ($input['limit'] ?? 100))),
            $owner === null ? [] : [$owner => $cursor],
            (bool) ($input['include_retired'] ?? true),
            $owner,
        );
        $report = $owner !== null ? ($result['owners'][$owner] ?? []) : null;
        return $result + [
            'owner' => $owner,
            'owner_status' => is_array($report) ? ($report['status'] ?? 'BLOCKED') : $result['status'],
            'owner_counts' => is_array($report) ? ['clusters' => count((array) ($report['clusters'] ?? [])), 'rows_read' => (int) ($report['rows_read'] ?? 0)] : ($result['counts'] ?? []),
            'next_cursor' => is_array($report) ? ($report['next_cursor'] ?? null) : null,
            'completeness' => is_array($report) ? (($report['status'] ?? '') === 'COMPLETE' ? 'COMPLETE' : ($report['status'] ?? 'BLOCKED')) : $result['status'],
        ];
    }
}
