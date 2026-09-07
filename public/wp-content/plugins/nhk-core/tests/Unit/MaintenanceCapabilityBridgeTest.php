<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Maintenance\MaintenanceCapabilityBridge;
use PHPUnit\Framework\TestCase;

final class MaintenanceCapabilityBridgeTest extends TestCase
{
    public function test_each_maintenance_read_uses_the_registered_mcp_tool(): void
    {
        $calls = [];
        $call = static function (string $tool, array $arguments) use (&$calls): array {
            $calls[] = [$tool, $arguments];
            return ['status' => 'available', 'read_only' => true];
        };

        MaintenanceCapabilityBridge::call('canonical-inventory', ['limit' => 100], $call);
        MaintenanceCapabilityBridge::call('graph-inventory', ['limit' => 100], $call);
        MaintenanceCapabilityBridge::call('relation-dry-run', ['records' => []], $call);

        self::assertSame([
            ['nhk.canonical.inventory', ['limit' => 100]],
            ['nhk.graph.inventory', ['limit' => 100]],
            ['nhk.relation.backfill.dry_run', ['records' => []]],
        ], $calls);
    }

    public function test_unknown_maintenance_read_fails_closed(): void
    {
        self::expectExceptionMessage('MAINTENANCE_CAPABILITY_NOT_ALLOWLISTED');
        MaintenanceCapabilityBridge::call('inventory', [], static fn (): array => []);
    }

    public function test_inventory_maintenance_payload_omits_nullable_cursor(): void
    {
        $entrypoint = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/nhk-core-maintenance.php');

        self::assertStringNotContainsString("'after' => null", $entrypoint);
    }
}
