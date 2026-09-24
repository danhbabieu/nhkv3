<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpAbilityRegistration;
use NHK\Core\Application\Mcp\McpToolCatalog;
use PHPUnit\Framework\TestCase;

final class KnowledgeWriterPreviewExposureTest extends TestCase
{
    public function test_production_composition_wires_the_read_only_facade_to_mcp_transport(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        $service = strpos($plugin, '$knowledgeWriterPreview = new KnowledgeWriterPreviewService(');
        $transport = strpos($plugin, 'new McpTransport(');
        $injection = strpos($plugin, 'knowledgeWriterPreview: $knowledgeWriterPreview');

        self::assertNotFalse($service, 'The production composition must construct the preview façade.');
        self::assertNotFalse($transport, 'The production composition must construct MCP transport.');
        self::assertNotFalse($injection, 'MCP transport must receive the preview façade.');
        self::assertLessThan($transport, $service);
        self::assertGreaterThan($transport, $injection);

        $construction = substr($plugin, $service, $transport - $service);
        self::assertStringContainsString('$researchResolver', $construction);
        self::assertStringNotContainsString('new McpSemanticContextResolver(', $construction);
        self::assertStringContainsString('$captureSubjectResolver', $construction);
        self::assertStringContainsString('$sharedEnrichment', $construction);
    }

    public function test_preview_is_exposed_through_mcp_without_a_wordpress_ability_or_mutation_allowlist(): void
    {
        $tool = null;
        foreach (McpToolCatalog::tools() as $candidate) {
            if (($candidate['name'] ?? null) === 'nhk.knowledge.writer.preview') $tool = $candidate;
        }

        self::assertIsArray($tool);
        self::assertSame('read', $tool['kind']);
        self::assertFalse($tool['governed']);
        self::assertNotEmpty($tool['dispatch']);
        self::assertSame(64, strlen(McpToolCatalog::schemaHash('nhk.knowledge.writer.preview')));
        self::assertSame('nhk-v3/knowledge-writer-preview', McpAbilityRegistration::abilityNameForTool('nhk.knowledge.writer.preview'));
        self::assertContains('nhk-v3/knowledge-writer-preview', McpAbilityRegistration::operatorEnabledAbilityAllowlist());
        self::assertNotContains('nhk-v3/knowledge-writer-preview', McpAbilityRegistration::explicitInternalAdminAbilityAllowlist());
        self::assertArrayNotHasKey('nhk.knowledge.writer.preview', McpAbilityRegistration::explicitExclusionReasons());
    }
}
