<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpDispatchRegistry, McpReadHandler, McpSemanticContextResolver, McpToolCatalog, McpTransport};
use NHK\Core\Application\Mcp\McpAbilityRegistration;
use NHK\Core\Infrastructure\Mcp\EasyMcpNativeFileCompatibilityAdapter;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryProposalRepository};
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/KnowledgeWriterPreviewServiceTest.php';

final class KnowledgeWriterPreviewMcpTest extends TestCase
{
    use KnowledgeWriterPreviewFixture;

    public function test_preview_is_catalogued_as_read_only_and_executable(): void
    {
        $tool = $this->tool('nhk.knowledge.writer.preview');

        self::assertSame('read', $tool['kind']);
        self::assertFalse($tool['governed']);
        self::assertSame('nhk.knowledge.writer.preview', McpDispatchRegistry::handlerKey('nhk.knowledge.writer.preview'));
        self::assertTrue(McpToolCatalog::hasExecutableDispatchHandler('nhk.knowledge.writer.preview'));
        self::assertSame('nhk-v3/knowledge-writer-preview', McpAbilityRegistration::abilityNameForTool('nhk.knowledge.writer.preview'));
        self::assertContains('nhk-v3/knowledge-writer-preview', McpAbilityRegistration::operatorEnabledAbilityAllowlist());
        self::assertArrayNotHasKey('nhk.knowledge.writer.preview', McpAbilityRegistration::explicitExclusionReasons());

        $schema = $tool['inputSchema'];
        self::assertContains('instruction', $schema['required']);
        self::assertSame(false, $schema['additionalProperties']);
        self::assertSame(12, $schema['properties']['requested_facets']['maxItems']);
        self::assertSame(1000, $schema['properties']['instruction']['maxLength']);
        self::assertSame(12, $schema['properties']['observations']['maxItems']);
        self::assertSame(4000, $schema['properties']['output_constraints']['properties']['max_chars']['maximum']);
    }

    public function test_advertised_read_only_capability_is_exported_with_catalog_schema_and_known_capability_remains_exported(): void
    {
        $catalog = array_column(McpToolCatalog::tools(), null, 'name');
        $connectorTools = [];
        foreach (['nhk.knowledge.writer.preview', 'nhk.documentation.bootstrap'] as $toolName) {
            $ability = McpAbilityRegistration::abilityNameForTool($toolName);
            self::assertNotNull($ability);
            $connectorTools[] = [
                'name' => McpAbilityRegistration::connectorToolNameForAbility($ability),
                'description' => 'stale',
                'inputSchema' => ['type' => 'object', 'properties' => ['stale' => ['type' => 'string']]],
            ];
        }

        $projected = array_column(EasyMcpNativeFileCompatibilityAdapter::projectTools($connectorTools), null, 'name');
        foreach (['nhk.knowledge.writer.preview', 'nhk.documentation.bootstrap'] as $toolName) {
            $ability = McpAbilityRegistration::abilityNameForTool($toolName);
            $connector = McpAbilityRegistration::connectorToolNameForAbility((string) $ability);
            self::assertArrayHasKey($connector, $projected, $toolName);
            self::assertSame($catalog[$toolName]['description'], $projected[$connector]['description'], $toolName);
            self::assertJsonStringEqualsJsonString(
                json_encode(EasyMcpNativeFileCompatibilityAdapter::normalizeFinalInputSchema($catalog[$toolName]['inputSchema']), JSON_THROW_ON_ERROR),
                json_encode($projected[$connector]['inputSchema'], JSON_THROW_ON_ERROR),
                $toolName,
            );
        }
    }

    public function test_missing_easy_mcp_descriptors_are_materialized_for_every_public_catalog_read(): void
    {
        $projected = array_column(EasyMcpNativeFileCompatibilityAdapter::projectTools([]), null, 'name');
        $parity = McpAbilityRegistration::callableParity();

        foreach (McpToolCatalog::tools() as $tool) {
            $toolName = (string) $tool['name'];
            if (($parity[$toolName]['easy_mcp_descriptor_exposed'] ?? false) !== true) continue;

            $ability = McpAbilityRegistration::abilityNameForTool($toolName);
            self::assertNotNull($ability, $toolName);
            $connector = McpAbilityRegistration::connectorToolNameForAbility($ability);
            self::assertArrayHasKey($connector, $projected, $toolName);
            self::assertSame($tool['description'], $projected[$connector]['description'], $toolName);
        }

        $preview = $projected['wp_ability_nhk_v3_knowledge_writer_preview'];
        self::assertSame(
            EasyMcpNativeFileCompatibilityAdapter::normalizeFinalInputSchema(
                array_column(McpToolCatalog::tools(), null, 'name')['nhk.knowledge.writer.preview']['inputSchema'],
            ),
            $preview['inputSchema'],
        );
        self::assertSame(McpToolCatalog::schemaHash('nhk.knowledge.writer.preview'), $preview['_meta']['nhk/schemaHash']);
    }

    public function test_tools_call_dispatches_structured_preview_with_read_capability_only(): void
    {
        $capabilities = [];
        $authority = $this->authority;
        $types = $this->types;
        $read = new McpReadHandler(
            $authority,
            $types,
            $this->createMock(\NHK\Core\Contracts\Media\MediaRepository::class),
            $this->createMock(\NHK\Core\Contracts\Media\MediaAssetRepository::class),
            $this->createMock(\NHK\Core\Contracts\Media\MediaUsageRepository::class),
            $this->createMock(\NHK\Core\Contracts\Video\VideoRepository::class),
            $this->createMock(\NHK\Core\Contracts\Knowledge\KnowledgeRepository::class),
            $this->createMock(\NHK\Core\Contracts\Knowledge\EvidenceRepository::class),
            resolver: new McpSemanticContextResolver($authority, $types),
        );
        $transport = new McpTransport(
            $read,
            new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
            static function (string $capability) use (&$capabilities): bool {
                $capabilities[] = $capability;
                return $capability === 'read';
            },
            knowledgeWriterPreview: $this->service(),
        );
        $response = $transport->dispatch([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'nhk.knowledge.writer.preview', 'arguments' => $this->request()],
        ], ['Mcp-Name' => 'nhk.knowledge.writer.preview']);

        self::assertSame(200, $response['status']);
        self::assertFalse($response['body']['result']['isError']);
        self::assertTrue($response['body']['result']['structuredContent']['read_only']);
        self::assertSame(['read'], $capabilities);
    }

    public function test_tools_call_fails_closed_when_preview_service_is_missing(): void
    {
        $read = new McpReadHandler(
            $this->authority,
            $this->types,
            $this->createMock(\NHK\Core\Contracts\Media\MediaRepository::class),
            $this->createMock(\NHK\Core\Contracts\Media\MediaAssetRepository::class),
            $this->createMock(\NHK\Core\Contracts\Media\MediaUsageRepository::class),
            $this->createMock(\NHK\Core\Contracts\Video\VideoRepository::class),
            $this->createMock(\NHK\Core\Contracts\Knowledge\KnowledgeRepository::class),
            $this->createMock(\NHK\Core\Contracts\Knowledge\EvidenceRepository::class),
            resolver: new McpSemanticContextResolver($this->authority, $this->types),
        );
        $transport = new McpTransport(
            $read,
            new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
            static fn (string $capability): bool => $capability === 'read',
        );

        $response = $transport->dispatch([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'nhk.knowledge.writer.preview', 'arguments' => $this->request()],
        ], ['Mcp-Name' => 'nhk.knowledge.writer.preview']);

        self::assertSame(200, $response['status']);
        self::assertTrue($response['body']['result']['isError']);
        self::assertSame('KNOWLEDGE_WRITER_PREVIEW_UNAVAILABLE', $response['body']['result']['structuredContent']['error']['code']);
    }

    private function tool(string $name): array
    {
        foreach (McpToolCatalog::tools() as $tool) if ($tool['name'] === $name) return $tool;
        self::fail('Preview tool is missing from the MCP catalog.');
    }
}
