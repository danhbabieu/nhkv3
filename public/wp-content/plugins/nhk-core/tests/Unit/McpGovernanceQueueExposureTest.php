<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpToolCatalog, SingleEntryPointPolicy};
use PHPUnit\Framework\TestCase;

final class McpGovernanceQueueExposureTest extends TestCase
{
    public function test_admin_queue_does_not_change_operator_catalog_or_capture_entry_point(): void
    {
        $tools = McpToolCatalog::tools();
        $names = array_column($tools, 'name');
        $internalTools = SingleEntryPointPolicy::internalOnlyTools();

        self::assertNotContains('nhk.governance.queue', $names);
        self::assertNotContains('nhk.admin.governance', $names);
        self::assertSame('nhk.capture.ingest', SingleEntryPointPolicy::CANONICAL_TOOL);
        self::assertContains(SingleEntryPointPolicy::CANONICAL_TOOL, $names);
        self::assertTrue(SingleEntryPointPolicy::isInternalOnly('nhk.proposal.apply'));
        self::assertSame('canonical', SingleEntryPointPolicy::surface(SingleEntryPointPolicy::CANONICAL_TOOL));

        foreach ($tools as $tool) {
            $name = (string) $tool['name'];
            self::assertSame(SingleEntryPointPolicy::surface($name), $tool['surface'], $name);
            self::assertSame((bool) ($tool['governed'] ?? false), McpToolCatalog::isGoverned($name), $name);
            if (in_array($name, $internalTools, true)) {
                self::assertContains($tool['surface'], ['internal_admin_only', 'governed_publication_continuation'], $name);
                self::assertNotContains(McpAbilityRegistration::abilityNameForTool($name), McpAbilityRegistration::operatorEnabledAbilityAllowlist(), $name);
            }
        }
    }

    public function test_easy_mcp_operator_allowlist_remains_unchanged_and_excludes_internal_writers(): void
    {
        $allowlist = McpAbilityRegistration::operatorEnabledAbilityAllowlist();
        $catalogAbilityNames = [];
        foreach (McpToolCatalog::tools() as $tool) {
            $name = (string) $tool['name'];
            $ability = McpAbilityRegistration::abilityNameForTool($name);
            self::assertNotNull($ability, $name);
            $catalogAbilityNames[] = $ability;
            if (!SingleEntryPointPolicy::isInternalOnly($name)) {
                self::assertContains($ability, $allowlist, $name);
            }
        }
        $registeredAbilityNames = McpAbilityRegistration::abilityNames();
        sort($catalogAbilityNames);
        sort($registeredAbilityNames);
        self::assertSame(array_values(array_unique($catalogAbilityNames)), array_values(array_unique($registeredAbilityNames)));
        $expectedAllowlist = [];
        foreach (McpToolCatalog::tools() as $tool) {
            $name = (string) $tool['name'];
            $ability = McpAbilityRegistration::abilityNameForTool($name);
            if ($ability !== null && !SingleEntryPointPolicy::isInternalOnly($name)) $expectedAllowlist[] = $ability;
        }
        self::assertSame(array_values(array_unique($expectedAllowlist)), $allowlist);

        $patterns = McpAbilityRegistration::canonicalEasyMcpAllowedToolPatterns();
        self::assertSame($patterns, McpAbilityRegistration::ensureEasyMcpAllowedToolPatterns($patterns));
        self::assertNotContains('*', $patterns);
        self::assertNotContains('wp_ability_nhk_v3_governance_queue', $patterns);

        foreach (SingleEntryPointPolicy::internalOnlyTools() as $tool) {
            self::assertNotContains(McpAbilityRegistration::abilityNameForTool($tool), McpAbilityRegistration::operatorEnabledAbilityAllowlist());
        }
    }

    public function test_queue_sources_do_not_register_mcp_or_add_a_generic_writer(): void
    {
        $directory = dirname(__DIR__, 2) . '/src/Infrastructure/Admin';
        $paths = glob($directory . '/GovernanceQueue*.php') ?: [];
        self::assertNotEmpty($paths);

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            self::assertStringNotContainsString('McpToolCatalog', $source, $path);
            self::assertStringNotContainsString('McpAbilityRegistration', $source, $path);
            self::assertDoesNotMatchRegularExpression('/\bwp_(?:insert|update|delete|create|publish)(?:_|\s*\()/i', $source, $path);
            self::assertDoesNotMatchRegularExpression('/\b(?:INSERT\s+INTO|UPDATE\s+[^\n]+\s+SET|DELETE\s+FROM)\b/i', $source, $path);
        }
    }
}
