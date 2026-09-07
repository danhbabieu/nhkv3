<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpToolCatalog};
use PHPUnit\Framework\TestCase;

final class McpSemanticInventoryContractTest extends TestCase
{
    public function test_three_inventory_capabilities_are_read_only_and_have_bounded_inputs(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        foreach (['nhk.canonical.inventory', 'nhk.graph.inventory', 'nhk.relation.backfill.dry_run'] as $name) {
            self::assertArrayHasKey($name, $tools);
            self::assertSame('read', $tools[$name]['kind']);
            self::assertFalse($tools[$name]['governed']);
        }
        self::assertSame('nhk-v3/canonical-inventory', McpAbilityRegistration::abilityNameForTool('nhk.canonical.inventory'));
        self::assertSame('nhk-v3/graph-inventory', McpAbilityRegistration::abilityNameForTool('nhk.graph.inventory'));
        self::assertSame('nhk-v3/relation-backfill-dry-run', McpAbilityRegistration::abilityNameForTool('nhk.relation.backfill.dry_run'));
        self::assertSame(100, $tools['nhk.graph.inventory']['inputSchema']['properties']['limit']['maximum']);
    }
}
