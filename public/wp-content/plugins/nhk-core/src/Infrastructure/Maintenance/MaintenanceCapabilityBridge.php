<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Maintenance;

/** Bridges maintenance reads to the one registered MCP implementation. */
final class MaintenanceCapabilityBridge
{
    /** @var array<string,string> */
    private const OPERATIONS = [
        'canonical-inventory' => 'nhk.canonical.inventory',
        'graph-inventory' => 'nhk.graph.inventory',
        'relation-dry-run' => 'nhk.relation.backfill.dry_run',
    ];

    /** @param callable(string,array<string,mixed>): array<string,mixed> $call */
    public static function call(string $operation, array $arguments, callable $call): array
    {
        $tool = self::OPERATIONS[$operation] ?? null;
        if ($tool === null) throw new \InvalidArgumentException('MAINTENANCE_CAPABILITY_NOT_ALLOWLISTED');
        $result = $call($tool, $arguments);
        if (!is_array($result)) throw new \RuntimeException('MAINTENANCE_CAPABILITY_INVALID_RECEIPT');
        return $result;
    }
}
