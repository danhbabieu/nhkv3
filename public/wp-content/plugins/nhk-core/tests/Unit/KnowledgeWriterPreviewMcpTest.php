<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpDispatchRegistry, McpReadHandler, McpSemanticContextResolver, McpToolCatalog, McpTransport};
use NHK\Core\Application\Mcp\McpAbilityRegistration;
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
        self::assertNull(McpAbilityRegistration::abilityNameForTool('nhk.knowledge.writer.preview'));
        self::assertNotEmpty(McpAbilityRegistration::explicitExclusionReasons()['nhk.knowledge.writer.preview']);

        $schema = $tool['inputSchema'];
        self::assertContains('instruction', $schema['required']);
        self::assertSame(false, $schema['additionalProperties']);
        self::assertSame(12, $schema['properties']['requested_facets']['maxItems']);
        self::assertSame(1000, $schema['properties']['instruction']['maxLength']);
        self::assertSame(12, $schema['properties']['observations']['maxItems']);
        self::assertSame(4000, $schema['properties']['output_constraints']['properties']['max_chars']['maximum']);
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

    private function tool(string $name): array
    {
        foreach (McpToolCatalog::tools() as $tool) if ($tool['name'] === $name) return $tool;
        self::fail('Preview tool is missing from the MCP catalog.');
    }
}
