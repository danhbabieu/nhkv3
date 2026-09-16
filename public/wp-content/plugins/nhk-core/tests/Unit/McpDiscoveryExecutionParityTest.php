<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpToolCatalog};
use PHPUnit\Framework\TestCase;

final class McpDiscoveryExecutionParityTest extends TestCase
{
    public function test_every_advertised_mutation_has_a_canonical_dispatch_handler(): void
    {
        foreach (McpToolCatalog::tools() as $tool) {
            if (($tool['kind'] ?? null) !== 'mutation') continue;

            self::assertTrue(
                McpToolCatalog::hasExecutableDispatchHandler((string) $tool['name']),
                'DISCOVERED_BUT_UNKNOWN_AT_CALL: ' . (string) $tool['name'],
            );
        }
    }

    public function test_every_public_executable_tool_is_discovery_listed(): void
    {
        $catalog = array_fill_keys(McpToolCatalog::names(), true);

        foreach (McpToolCatalog::executableToolNames() as $toolName) {
            self::assertArrayHasKey($toolName, $catalog, 'EXECUTABLE_NOT_DISCOVERY_LISTED: ' . $toolName);
        }
    }

    public function test_article_and_capture_tools_share_one_dispatch_contract(): void
    {
        foreach ([
            'nhk.article.draft.create',
            'nhk.article.draft.update',
            'nhk.article.publish.review',
            'nhk.article.publish.approve',
            'nhk.article.publish',
            'nhk.article.trash',
            'nhk.capture.ingest',
        ] as $toolName) {
            self::assertTrue(McpToolCatalog::hasExecutableDispatchHandler($toolName), $toolName);
            self::assertNotNull(McpAbilityRegistration::abilityNameForTool($toolName), $toolName);
        }
    }
}
