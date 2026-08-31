<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpToolCatalog;
use PHPUnit\Framework\TestCase;

final class McpContractTest extends TestCase
{
    public function test_read_tools_are_not_mutations_and_all_mutations_are_governed(): void
    {
        $tools = McpToolCatalog::tools();
        self::assertNotEmpty($tools);
        foreach ($tools as $tool) self::assertSame($tool['kind'] === 'mutation', $tool['governed']);
        self::assertContains('nhk.media.ingest', array_column($tools, 'name'));
        self::assertFalse(McpToolCatalog::isGoverned('nhk.search'));
        self::assertTrue(McpToolCatalog::isGoverned('nhk.media.ingest'));
        self::assertTrue(McpToolCatalog::isGoverned('nhk.proposal.create'));
        self::assertFalse(McpToolCatalog::isGoverned('nhk.unknown'));
    }
}
