<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Governance\{GovernanceService, ProposalEligibilityService};
use NHK\Core\Application\Knowledge\{EvidenceService, KnowledgeService, SourceService};
use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpGovernanceHandler, McpReadHandler, McpToolCatalog, McpTransport};
use NHK\Core\Application\Media\MediaService;
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository, InMemoryProposalRepository};
use PHPUnit\Framework\TestCase;

final class McpContractTest extends TestCase
{
    public function test_catalog_exposes_machine_readable_schemas_and_governance_flags(): void
    {
        $tools = McpToolCatalog::tools();
        self::assertNotEmpty($tools);
        foreach ($tools as $tool) {
            self::assertArrayHasKey('inputSchema', $tool);
            self::assertArrayHasKey('outputSchema', $tool);
            self::assertSame('object', $tool['inputSchema']['type']);
            self::assertSame('object', $tool['outputSchema']['type']);
            self::assertContains($tool['kind'], ['read', 'mutation']);
            if ($tool['kind'] === 'mutation') self::assertTrue($tool['governed']);
        }
    }

    public function test_transport_supports_mcp_initialize_and_tools_list(): void
    {
        $transport = $this->transport();
        $initialize = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
        self::assertSame(200, $initialize['status']);
        self::assertSame('2025-03-26', $initialize['body']['result']['protocolVersion']);
        self::assertSame('nhk-v3', $initialize['body']['result']['serverInfo']['name']);

        $list = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);
        self::assertSame(200, $list['status']);
        self::assertSame(array_column(McpToolCatalog::tools(), 'name'), array_column($list['body']['result']['tools'], 'name'));
    }

    public function test_transport_rejects_unknown_tool_and_invalid_rpc_shape(): void
    {
        $transport = $this->transport();
        $unknown = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.unknown', 'arguments' => []]]);
        self::assertSame(404, $unknown['status']);
        self::assertSame(-32601, $unknown['body']['error']['code']);

        $bad = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.entity.get']]);
        self::assertSame(400, $bad['status']);
        self::assertSame(-32602, $bad['body']['error']['code']);
    }

    public function test_transport_read_and_governed_calls_are_separated(): void
    {
        $transport = $this->transport();
        $read = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.search', 'arguments' => ['q' => 'anything']]]);
        self::assertSame(200, $read['status']);
        self::assertArrayHasKey('structuredContent', $read['body']['result']);

        $governed = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'nhk.proposal.create', 'arguments' => ['proposal_id' => 'bad']]]);
        self::assertContains($governed['status'], [400, 403, 409]);
    }

    public function test_catalog_contains_no_generic_semantic_wordpress_writer(): void
    {
        $names = array_column(McpToolCatalog::tools(), 'name');
        foreach ($names as $name) {
            self::assertStringNotContainsString('wp.', $name);
            self::assertStringNotContainsString('wordpress.', $name);
        }
    }

    public function test_governed_mutations_are_capability_gated(): void
    {
        $checked = [];
        $transport = $this->transport(static function (string $capability) use (&$checked): bool { $checked[] = $capability; return false; });
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'nhk.proposal.create', 'arguments' => ['operation' => 'test', 'command' => []]]]);
        self::assertSame(403, $response['status']);
        self::assertContains('nhk_manage_proposals', $checked);
    }

    public function test_proposal_eligibility_is_read_only_but_still_capability_gated(): void
    {
        $checked = [];
        $transport = $this->transport(static function (string $capability) use (&$checked): bool { $checked[] = $capability; return false; });
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'nhk.proposal.eligibility', 'arguments' => ['proposal_id' => 'bad']]]);
        self::assertSame(403, $response['status']);
        self::assertContains('nhk_manage_proposals', $checked);
    }

    public function test_tools_call_result_includes_content_and_structured_content(): void
    {
        $transport = $this->transport();
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'nhk.search', 'arguments' => ['q' => '']]]);
        self::assertSame(200, $response['status']);
        self::assertArrayHasKey('content', $response['body']['result']);
        self::assertArrayHasKey('structuredContent', $response['body']['result']);
    }

    public function test_wordpress_attachment_ingest_stages_multipart_file_safely(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nhk-mcp-file-');
        file_put_contents($path, 'binary');
        $ingestor = new class {
            public ?array $received = null;
            public function ingest(string $tmp, string $name, string $mime, ?int $width = null, ?int $height = null, ?int $quality = null): array
            {
                $this->received = [$tmp, $name, $mime, $width, $height, $quality];
                return ['attachment_id' => 77];
            }
        };
        $transport = $this->transport(null, $ingestor);
        try {
            $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'nhk.media.attachment.ingest', 'arguments' => ['file' => ['tmp_name' => $path, 'name' => 'Ảnh mặt tiền.JPG', 'type' => 'image/jpeg'], 'width' => 1600, 'height' => 1200, 'quality' => 84]]]);
            self::assertSame(200, $response['status']);
            self::assertSame(77, $response['body']['result']['structuredContent']['attachment_id']);
            self::assertIsArray($ingestor->received);
            self::assertSame($path, $ingestor->received[0]['tmp_name']);
            self::assertSame('Ảnh mặt tiền.JPG', $ingestor->received[1]);
            self::assertSame(1600, $ingestor->received[3]);
            self::assertSame(1200, $ingestor->received[4]);
            self::assertSame(84, $ingestor->received[5]);
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    public function test_wordpress_ability_allowlist_covers_the_catalog(): void
    {
        self::assertSame([
            'nhk-v3/search',
            'nhk-v3/semantic-resolve',
            'nhk-v3/article-preflight',
            'nhk-v3/category-resolve',
            'nhk-v3/entity-get',
            'nhk-v3/media-get',
            'nhk-v3/media-attachment-get',
            'nhk-v3/video-get',
            'nhk-v3/knowledge-get',
            'nhk-v3/source-get',
            'nhk-v3/evidence-get',
        ], McpAbilityRegistration::readAbilityNames());
        self::assertSame('nhk-v3/entity-get', McpAbilityRegistration::abilityNameForTool('nhk.entity.get'));
        self::assertSame('nhk-v3/video-ingest', McpAbilityRegistration::abilityNameForTool('nhk.video.ingest'));
        self::assertSame([
            'nhk-v3/public-url-reproject',
            'nhk-v3/article-ingest',
            'nhk-v3/category-create',
            'nhk-v3/category-update',
            'nhk-v3/category-assign',
            'nhk-v3/category-unassign',
            'nhk-v3/category-delete',
            'nhk-v3/article-draft-create',
            'nhk-v3/article-draft-update',
            'nhk-v3/article-publish',
            'nhk-v3/article-publish-review',
            'nhk-v3/article-publish-approve',
            'nhk-v3/article-trash',
            'nhk-v3/article-restore',
            'nhk-v3/video-ingest',
            'nhk-v3/knowledge-ingest',
            'nhk-v3/source-ingest',
            'nhk-v3/evidence-ingest',
            'nhk-v3/proposal-create',
            'nhk-v3/proposal-submit',
            'nhk-v3/proposal-approve',
            'nhk-v3/proposal-reject',
            'nhk-v3/proposal-apply',
        ], McpAbilityRegistration::governedAbilityNames());
        self::assertSame('nhk-v3/article-preflight', McpAbilityRegistration::abilityNameForTool('nhk.article.preflight'));
        self::assertSame('nhk-v3/article-ingest', McpAbilityRegistration::abilityNameForTool('nhk.article.ingest'));
        self::assertCount(count(McpToolCatalog::tools()) - 1, McpAbilityRegistration::abilityNames());
    }

    public function test_every_catalog_tool_is_registered_or_has_an_explicit_exclusion_reason(): void
    {
        $excluded = McpAbilityRegistration::explicitExclusionReasons();

        foreach (McpToolCatalog::tools() as $tool) {
            $name = $tool['name'];
            self::assertTrue(
                McpAbilityRegistration::abilityNameForTool($name) !== null || isset($excluded[$name]),
                sprintf('Catalog tool %s is silently omitted from Ability exposure.', $name)
            );
            if (isset($excluded[$name])) self::assertNotSame('', trim($excluded[$name]), $name);
        }
    }

    public function test_proposal_eligibility_is_read_only_but_capability_gated(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');

        self::assertSame('read', $tools['nhk.proposal.eligibility']['kind']);
        self::assertTrue($tools['nhk.proposal.eligibility']['governed']);
    }

    private function transport(?callable $capability = null, ?object $attachmentIngestor = null): McpTransport
    {
        $authority = new InMemoryAuthorityRepository();
        $types = new EntityTypeRegistry();
        $media = $this->createMock(MediaRepository::class);
        $assets = $this->createMock(MediaAssetRepository::class);
        $usages = $this->createMock(MediaUsageRepository::class);
        $videos = $this->createMock(VideoRepository::class);
        $claims = $this->createMock(KnowledgeRepository::class);
        $evidence = $this->createMock(EvidenceRepository::class);
        $sources = $this->createMock(SourceRepository::class);
        $read = new McpReadHandler($authority, $types, $media, $assets, $usages, $videos, $claims, $evidence, null, $sources, null, null, null);
        $governance = new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository()));
        return new McpTransport($read, $governance, $capability ? \Closure::fromCallable($capability) : static fn (string $name): bool => true, null, null, null, $attachmentIngestor);
    }
}
