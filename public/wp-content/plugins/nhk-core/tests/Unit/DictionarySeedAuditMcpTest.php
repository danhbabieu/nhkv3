<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryCorpusSourceReader, DictionaryResolver, DictionarySeedCorpusAuditCoordinator, DictionarySeedPlanner};
use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Mcp\{DictionarySeedAuditHandler, McpAbilityRegistration, McpCapabilityManifest, McpDispatchRegistry, McpGovernanceHandler, McpReadHandler, McpToolCatalog, McpTransport};
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use PHPUnit\Framework\TestCase;

final class DictionarySeedAuditMcpTest extends TestCase
{
    public function test_handler_is_bounded_privacy_safe_and_zero_write(): void
    {
        $handler = $this->handler();
        $response = $handler->audit([
            'text' => 'Private phrase Alpha.',
            'source_kind' => 'human_chat',
            'source_id' => 'chat:1',
            'hints' => ['Private phrase Alpha'],
            'limit' => 10,
        ]);
        $serialized = json_encode($response, JSON_THROW_ON_ERROR);

        self::assertSame('AVAILABLE', $response['status']);
        self::assertTrue($response['read_only']);
        self::assertFalse($response['mutated']);
        self::assertSame(1, $response['total']);
        self::assertStringNotContainsString('Private phrase Alpha', $serialized);
        self::assertSame('NEW_LEXICAL_CANDIDATE', $response['items'][0]['classification']);
        self::assertArrayHasKey('raw_form', $response['items'][0]);
        self::assertArrayHasKey('resolution_status', $response['items'][0]);
        self::assertArrayHasKey('suggested_action', $response['items'][0]);
    }

    public function test_handler_uses_character_bound_for_utf8_text(): void
    {
        $response = $this->handler()->audit(['text' => str_repeat('đ ', 5999) . 'đ', 'limit' => 1]);

        self::assertSame('AVAILABLE', $response['status']);
        self::assertTrue($response['read_only']);
        self::assertFalse($response['mutated']);
    }

    public function test_catalog_dispatch_and_ability_parity_is_internal_read_only(): void
    {
        $tool = array_values(array_filter(McpToolCatalog::tools(), static fn (array $tool): bool => $tool['name'] === 'nhk.dictionary.seed-audit'))[0] ?? null;
        self::assertIsArray($tool);
        self::assertSame('read', $tool['kind']);
        self::assertFalse($tool['governed']);
        self::assertSame('internal_admin_only', $tool['surface']);
        self::assertTrue(McpDispatchRegistry::hasHandler('nhk.dictionary.seed-audit'));
        self::assertSame('nhk-v3/dictionary-seed-audit', McpAbilityRegistration::abilityNameForTool('nhk.dictionary.seed-audit'));
        self::assertContains('nhk-v3/dictionary-seed-audit', McpAbilityRegistration::explicitInternalAdminReadOnlyAbilityAllowlist());
        self::assertContains('nhk.dictionary.seed-audit', McpCapabilityManifest::all()['dictionary']['reads']);
        self::assertSame('wp_ability_nhk_v3_dictionary_seed_audit', McpAbilityRegistration::connectorToolNameForAbility('nhk-v3/dictionary-seed-audit'));
        self::assertSame('nhk.dictionary.seed-audit', McpAbilityRegistration::toolNameForConnectorTool('wp_ability_nhk_v3_dictionary_seed_audit'));
        self::assertContains('nhk.dictionary.seed-audit', array_column(McpToolCatalog::tools(), 'name'));
        self::assertArrayHasKey('source_scope', $tool['inputSchema']['properties']);
        self::assertNotContains('text', $tool['inputSchema']['required'] ?? []);
    }

    public function test_corpus_scope_uses_the_same_internal_tool_and_returns_bounded_aggregate(): void
    {
        $reader = new class implements DictionaryCorpusSourceReader {
            public function page(?string $after, int $limit): array { return ['items' => [['source_id' => 'knowledge:1', 'source_family' => 'family:1', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Corpus Term', 'context' => ['lexical_hints' => ['Corpus Term']]]], 'has_more' => false]; }
        };
        $resolver = new DictionaryResolver(static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false);
        $handler = new DictionarySeedAuditHandler(new DictionarySeedPlanner($resolver), corpus: new DictionarySeedCorpusAuditCoordinator(['KNOWLEDGE' => $reader], new StructuredSemanticInterpreter(), new DictionarySeedPlanner($resolver)));
        $result = $handler->audit(['source_scope' => 'KNOWLEDGE', 'limit' => 10]);
        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame(1, $result['sources_scanned']);
        self::assertSame(1, $result['unique_normalized_terms']);
        self::assertTrue($result['read_only']);
        self::assertFalse($result['mutated']);
    }

    public function test_tools_list_discovers_the_internal_read_only_operation(): void
    {
        $response = $this->transport(static fn (string $capability): bool => $capability === 'nhk_view_governance')
            ->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $tools = $response['body']['result']['tools'] ?? [];
        $names = array_column($tools, 'name');

        self::assertSame(200, $response['status']);
        self::assertContains('nhk.dictionary.seed-audit', $names);
    }

    public function test_transport_forbids_non_capable_actor_before_handler(): void
    {
        $transport = $this->transport(static fn (string $capability): bool => false);
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.dictionary.seed-audit', 'arguments' => ['text' => 'term']]]);

        self::assertTrue($response['body']['result']['isError']);
        self::assertSame('DIRECT_WRITE_BLOCKED', $response['body']['result']['structuredContent']['error']['code']);
    }

    private function handler(): DictionarySeedAuditHandler
    {
        $resolver = new DictionaryResolver(static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false);
        return new DictionarySeedAuditHandler(new DictionarySeedPlanner($resolver));
    }

    private function transport(callable $can): McpTransport
    {
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
        );
        return new McpTransport($read, new McpGovernanceHandler(new GovernanceService(new \NHK\Tests\Support\InMemoryProposalRepository())), $can, dictionarySeedAudit: $this->handler());
    }
}
