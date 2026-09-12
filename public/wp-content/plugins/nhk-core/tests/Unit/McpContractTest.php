<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpToolCatalog;
use NHK\Core\Application\Mcp\McpAbilityRegistration;
use NHK\Core\Application\Mcp\{McpDocumentationRegistry, McpGovernanceHandler, McpReadHandler, McpTransport, SingleEntryPointPolicy};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Contracts\Media\WordPressMediaAttachmentIngestor;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Infrastructure\Media\WordPressMediaAttachmentIngestor as ConcreteWordPressMediaAttachmentIngestor;
use NHK\Tests\Support\InMemoryProposalRepository;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class McpContractTest extends TestCase
{
    public function test_governed_ingest_response_separates_proposal_and_canonical_identity(): void
    {
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class),
            $this->createMock(MediaUsageRepository::class), $this->createMock(VideoRepository::class),
            $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            null, $this->createMock(SourceRepository::class), null, null, null,
        );
        $transport = new McpTransport($read, new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())));
        $proposalId = UuidCodec::newV7();
        $reflection = new \ReflectionMethod($transport, 'ingestProposal');
        $result = $reflection->invoke($transport, new Proposal($proposalId, 'source', 'ingest', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'test'));

        self::assertSame($proposalId, $result['proposal_id']);
        self::assertSame('draft', $result['proposal_state']);
        self::assertNull($result['target_uuid']);
        self::assertNull($result['canonical_id']);
        self::assertArrayNotHasKey('id', $result);
    }
    public function test_catalog_has_exact_current_ordered_tool_contract(): void
    {
        self::assertSame([
            'nhk.documentation.bootstrap',
            'nhk.documentation.get',
            'nhk.documentation.list',
            'nhk.docs.bootstrap',
            'nhk.docs.get',
            'nhk.search',
            'nhk.canonical.inventory',
            'nhk.graph.inventory',
            'nhk.relation.backfill.dry_run',
            'nhk.relation.backfill.apply',
            'nhk.semantic.resolve',
            'nhk.entity.neighborhood',
            'nhk.article.preflight',
            'nhk.article.ingest',
            'nhk.capture.ingest',
            'nhk.category.resolve',
            'nhk.category.create',
            'nhk.category.update',
            'nhk.category.assign',
            'nhk.category.unassign',
            'nhk.category.delete',
            'nhk.article.draft.create',
            'nhk.article.draft.update',
            'nhk.article.publish', 'nhk.article.publish.review', 'nhk.article.publish.approve', 'nhk.article.trash', 'nhk.article.restore',
            'nhk.entity.get',
            'nhk.media.get',
            'nhk.media.upload-batch',
            'nhk.media.ingest',
            'nhk.media.attachment.get',
            'nhk.video.ingest',
            'nhk.video.get',
            'nhk.knowledge.get',
            'nhk.source.get',
            'nhk.evidence.get',
            'nhk.knowledge.ingest',
            'nhk.source.ingest',
            'nhk.evidence.ingest',
            'nhk.public-url.audit',
            'nhk.public-url.reproject',
            'nhk.proposal.create',
            'nhk.proposal.submit',
            'nhk.proposal.review',
            'nhk.proposal.approve',
            'nhk.proposal.reject',
            'nhk.proposal.eligibility',
            'nhk.proposal.apply',
        ], array_column(McpToolCatalog::tools(), 'name'));
    }

    public function test_documentation_tools_are_read_only_and_allowlisted(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        self::assertFalse($tools['nhk.docs.bootstrap']['governed']);
        self::assertFalse($tools['nhk.docs.get']['governed']);
        self::assertSame(McpDocumentationRegistry::documentKeys(), $tools['nhk.docs.get']['inputSchema']['properties']['document_key']['enum']);
        self::assertSame([], $tools['nhk.docs.bootstrap']['inputSchema']['required']);
        self::assertFalse($tools['nhk.documentation.bootstrap']['governed']);
        self::assertSame(['path'], $tools['nhk.documentation.get']['inputSchema']['required']);
        self::assertArrayHasKey('line_count', $tools['nhk.documentation.get']['inputSchema']['properties']);
        self::assertArrayHasKey('path_prefix', $tools['nhk.documentation.list']['inputSchema']['properties']);
    }

    public function test_documentation_tools_dispatch_through_the_read_capability(): void
    {
        $transport = new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => $capability === 'read');
        $bootstrap = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.docs.bootstrap', 'arguments' => []]]);
        self::assertSame(200, $bootstrap['status']);
        self::assertSame('constitution', $bootstrap['body']['result']['structuredContent']['constitution']['document_key']);
        $document = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'nhk.docs.get', 'arguments' => ['document_key' => 'read-first']]]);
        self::assertSame(200, $document['status']);
        self::assertStringContainsString('Mandatory Read-First Router', $document['body']['result']['structuredContent']['content']);
    }

    public function test_canonical_documentation_tools_support_list_and_line_ranges(): void
    {
        $transport = new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => $capability === 'read');
        $list = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'nhk.documentation.list', 'arguments' => ['status' => 'ACTIVE', 'path_prefix' => 'docs/architecture/']]]);
        self::assertSame(200, $list['status']);
        self::assertNotEmpty($list['body']['result']['structuredContent']['files']);
        foreach ($list['body']['result']['structuredContent']['files'] as $entry) self::assertStringStartsWith('docs/architecture/', $entry['path']);
        $page = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'nhk.documentation.get', 'arguments' => ['path' => 'docs/constitution/READ_FIRST.md', 'start_line' => 1, 'line_count' => 2]]]);
        self::assertSame(200, $page['status']);
        self::assertSame(1, $page['body']['result']['structuredContent']['start_line']);
        self::assertSame(2, $page['body']['result']['structuredContent']['end_line']);
        self::assertTrue($page['body']['result']['structuredContent']['has_more']);
    }

    public function test_documentation_permission_is_not_higher_than_mutation_permission(): void
    {
        $transport = new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => $capability === 'nhk_ingest_articles');
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'nhk.documentation.bootstrap', 'arguments' => []]]);
        self::assertSame(403, $response['status']);
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => ['idempotency_key' => 'no-read', 'documentation_checkpoint' => ['manifest_hash' => str_repeat('a', 64), 'documentation_version' => str_repeat('b', 64)]]]]);
        self::assertSame(403, $response['status']);
    }

    public function test_capture_rejects_a_stale_documentation_checkpoint_before_mutation(): void
    {
        $transport = new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => true);
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => [
            'idempotency_key' => 'stale-checkpoint',
            'documentation_checkpoint' => ['manifest_hash' => str_repeat('a', 64), 'documentation_version' => str_repeat('b', 64)],
        ]]]);
        self::assertSame(200, $response['status']);
        self::assertTrue($response['body']['result']['isError']);
        self::assertSame('DOCUMENTATION_CHECKPOINT_STALE', $response['body']['result']['structuredContent']['error']['code']);
    }

    public function test_capture_writes_fail_closed_when_required_runtime_schema_is_stale(): void
    {
        $transport = new McpTransport(
            $this->readHandler(),
            new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
            static fn (string $capability): bool => true,
            runtimeWriteReady: static fn (): bool => false,
        );
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => [
            'idempotency_key' => 'stale-schema',
            'text' => 'Không được ghi khi schema chưa sẵn sàng.',
            'documentation_checkpoint' => ['manifest_hash' => str_repeat('a', 64), 'documentation_version' => str_repeat('b', 64)],
        ]] ]);

        self::assertSame(200, $response['status']);
        self::assertTrue($response['body']['result']['isError']);
        self::assertSame('REQUIRED_SCHEMA_NOT_READY', $response['body']['result']['content'][0]['text']);
    }

    public function test_new_submission_has_one_canonical_entry_point_and_direct_writers_are_internal_only(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        self::assertSame('nhk.capture.ingest', SingleEntryPointPolicy::CANONICAL_TOOL);
        self::assertSame('canonical', $tools['nhk.capture.ingest']['surface']);
        self::assertSame('internal_admin_only', $tools['nhk.media.ingest']['surface']);
        self::assertSame('internal_admin_only', $tools['nhk.video.ingest']['surface']);
        self::assertSame('internal_admin_only', $tools['nhk.knowledge.ingest']['surface']);
        self::assertArrayHasKey('video', $tools['nhk.capture.ingest']['inputSchema']['properties']);
        self::assertContains('nhk.article.publish', SingleEntryPointPolicy::internalOnlyTools());
    }

    public function test_publication_continuation_is_the_only_internal_lifecycle_surface_exposed_for_rest_discovery(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        $expectedTools = [
            'nhk.article.publish.review',
            'nhk.article.publish.approve',
            'nhk.article.publish',
        ];

        self::assertSame($expectedTools, SingleEntryPointPolicy::publicationContinuationTools());
        $abilityNames = [
            'nhk.article.publish.review' => 'nhk-v3/article-publish-review',
            'nhk.article.publish.approve' => 'nhk-v3/article-publish-approve',
            'nhk.article.publish' => 'nhk-v3/article-publish',
        ];
        foreach ($expectedTools as $tool) {
            self::assertTrue(SingleEntryPointPolicy::isInternalOnly($tool));
            self::assertTrue(SingleEntryPointPolicy::isPublicationContinuation($tool));
            self::assertSame('governed_publication_continuation', $tools[$tool]['surface']);
            self::assertTrue($tools[$tool]['governed']);
            self::assertSame($abilityNames[$tool], McpAbilityRegistration::abilityNameForTool($tool));
        }

        self::assertSame('internal_admin_only', $tools['nhk.article.draft.update']['surface']);
        self::assertNotContains('nhk-v3/article-publish', McpAbilityRegistration::operatorEnabledAbilityAllowlist());
    }

    public function test_direct_writer_fails_closed_without_internal_boundary(): void
    {
        $transport = new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => $capability === 'nhk_create_proposals');
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 77, 'method' => 'tools/call', 'params' => ['name' => 'nhk.knowledge.ingest', 'arguments' => ['stable_key' => 'direct.blocked', 'text' => 'blocked']]]);
        self::assertSame(200, $response['status']);
        self::assertTrue($response['body']['result']['isError']);
        self::assertSame('DIRECT_WRITE_BLOCKED', $response['body']['result']['structuredContent']['error']['code']);
        self::assertSame('USE_CANONICAL_CAPTURE_FLOW', $response['body']['result']['structuredContent']['error']['reason']);
    }

    public function test_every_registered_direct_mutation_is_internal_guarded(): void
    {
        foreach (SingleEntryPointPolicy::internalOnlyTools() as $tool) {
            try {
                SingleEntryPointPolicy::guard($tool, static fn (string $capability): bool => false);
                self::fail('Direct mutation was not guarded: ' . $tool);
            } catch (\Throwable $error) {
                self::assertInstanceOf(\NHK\Core\Application\Mcp\SingleEntryPointViolation::class, $error);
                self::assertSame('DIRECT_WRITE_BLOCKED', $error->reasonCode);
                self::assertSame('USE_CANONICAL_CAPTURE_FLOW', $error->toArray()['reason']);
            }
        }
    }

    private function readHandler(): McpReadHandler
    {
        return new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class),
            $this->createMock(MediaUsageRepository::class), $this->createMock(VideoRepository::class),
            $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
        );
    }

    public function test_generic_proposal_declares_only_existing_governed_operations(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        self::assertSame([
            'create',
            'ingest',
            'relation_create',
            'rekey',
            'merge',
            'rename',
            'update',
            'retire',
            'reactivate',
            'collector_facet_update',
            'relation_retire',
            'relation_reactivate',
        ], $tools['nhk.proposal.create']['inputSchema']['properties']['operation']['enum']);
    }

    public function test_article_abilities_are_coordinated_and_phase_one_is_reconcile_only(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        self::assertSame('read', $tools['nhk.article.preflight']['kind']);
        self::assertFalse($tools['nhk.article.preflight']['governed']);
        self::assertSame('mutation', $tools['nhk.article.ingest']['kind']);
        self::assertTrue($tools['nhk.article.ingest']['governed']);
        self::assertSame(['reconcile', 'create', 'update'], $tools['nhk.article.ingest']['inputSchema']['properties']['intent']['enum']);
        self::assertSame(['idempotency_key', 'intent'], $tools['nhk.article.ingest']['inputSchema']['required']);
        self::assertSame(['intent'], $tools['nhk.article.preflight']['inputSchema']['required']);
        self::assertSame(['endpoint_type', 'endpoint_key'], $tools['nhk.article.ingest']['inputSchema']['properties']['target_wp_post']['required']);
        self::assertArrayNotHasKey('body', $tools['nhk.article.ingest']['inputSchema']['properties']);
    }

    public function test_read_tools_are_not_mutations_and_all_mutations_are_governed(): void
    {
        $tools = McpToolCatalog::tools();
        self::assertNotEmpty($tools);
        foreach ($tools as $tool) self::assertSame($tool['kind'] === 'mutation', $tool['governed']);
        self::assertContains('nhk.media.ingest', array_column($tools, 'name'));
        self::assertContains('nhk.video.ingest', array_column($tools, 'name'));
        self::assertContains('nhk.knowledge.ingest', array_column($tools, 'name'));
        self::assertContains('nhk.source.ingest', array_column($tools, 'name'));
        self::assertContains('nhk.evidence.ingest', array_column($tools, 'name'));
        self::assertFalse(McpToolCatalog::isGoverned('nhk.search'));
        self::assertTrue(McpToolCatalog::isGoverned('nhk.media.ingest'));
        self::assertTrue(McpToolCatalog::isGoverned('nhk.video.ingest'));
        self::assertTrue(McpToolCatalog::isGoverned('nhk.knowledge.ingest'));
        self::assertTrue(McpToolCatalog::isGoverned('nhk.source.ingest'));
        self::assertTrue(McpToolCatalog::isGoverned('nhk.evidence.ingest'));
        self::assertTrue(McpToolCatalog::isGoverned('nhk.proposal.create'));
        self::assertFalse(McpToolCatalog::isGoverned('nhk.unknown'));
    }

    public function test_canonical_id_tool_fields_declare_uuid_shape_validation(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        foreach (['nhk.entity.get', 'nhk.media.get', 'nhk.video.get', 'nhk.knowledge.get', 'nhk.source.get', 'nhk.evidence.get', 'nhk.proposal.submit', 'nhk.proposal.approve', 'nhk.proposal.reject', 'nhk.proposal.eligibility', 'nhk.proposal.apply'] as $name) {
            self::assertSame('^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-8][0-9A-Fa-f]{3}-[89ABab][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}$', $tools[$name]['inputSchema']['properties']['id']['pattern'], $name);
        }
        self::assertSame('^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-8][0-9A-Fa-f]{3}-[89ABab][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}$', $tools['nhk.evidence.ingest']['inputSchema']['properties']['claim_id']['pattern']);
        self::assertSame('^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-8][0-9A-Fa-f]{3}-[89ABab][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}$', $tools['nhk.evidence.ingest']['inputSchema']['properties']['source_id']['pattern']);
        self::assertSame('uuid', $tools['nhk.entity.get']['inputSchema']['properties']['id']['format']);
        self::assertSame(['string', 'null'], $tools['nhk.proposal.create']['inputSchema']['properties']['target_uuid']['type']);
        self::assertSame(['string', 'null'], $tools['nhk.video.ingest']['inputSchema']['properties']['thumbnail_media_id']['type']);
        self::assertArrayHasKey('subject_id', $tools['nhk.proposal.create']['inputSchema']['properties']);
        self::assertSame('^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-8][0-9A-Fa-f]{3}-[89ABab][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}$', $tools['nhk.proposal.create']['inputSchema']['properties']['target_uuid']['pattern']);
        self::assertSame('^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-8][0-9A-Fa-f]{3}-[89ABab][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}$', $tools['nhk.proposal.create']['inputSchema']['properties']['dependency_ids']['items']['pattern']);
        self::assertSame(1, $tools['nhk.proposal.create']['inputSchema']['properties']['expected_revision']['minimum']);
    }

    public function test_proposal_review_is_read_only_and_exposes_approval_bindings(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        self::assertArrayHasKey('nhk.proposal.review', $tools);
        self::assertFalse($tools['nhk.proposal.review']['governed']);
        self::assertSame(['id'], $tools['nhk.proposal.review']['inputSchema']['required']);
        self::assertSame('nhk-v3/proposal-review', McpAbilityRegistration::abilityNameForTool('nhk.proposal.review'));
    }

    public function test_media_ingest_declares_complete_nested_asset_and_usage_contracts(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        $schema = $tools['nhk.media.ingest']['inputSchema']['properties'];
        self::assertSame(['kind', 'storage_key', 'checksum', 'mime_type', 'byte_size'], $schema['assets']['items']['required']);
        self::assertFalse($schema['assets']['items']['additionalProperties']);
        self::assertSame('^[0-9A-Fa-f]{64}$', $schema['assets']['items']['properties']['checksum']['pattern']);
        self::assertSame(['endpoint_type', 'endpoint_key', 'role'], $schema['usages']['items']['required']);
        self::assertFalse($schema['usages']['items']['additionalProperties']);
        self::assertSame(['featured_primary', 'inline_primary', 'inline_supporting', 'featured', 'inline', 'gallery', 'thumbnail', 'source', 'representative', 'evidence', 'technical_detail'], $schema['usages']['items']['properties']['role']['enum']);
        self::assertSame(1, $tools['nhk.media.ingest']['inputSchema']['properties']['name']['minLength']);
        self::assertSame(1, $schema['assets']['items']['properties']['storage_key']['minLength']);
        self::assertSame(1, $schema['assets']['items']['properties']['mime_type']['minLength']);
        self::assertSame(1, $schema['usages']['items']['properties']['endpoint_key']['minLength']);
        self::assertSame('^[a-z0-9][a-z0-9._:-]{0,190}$', $tools['nhk.media.ingest']['inputSchema']['properties']['stable_key']['pattern']);
        self::assertSame('uri', $tools['nhk.video.ingest']['inputSchema']['properties']['url']['format']);
        self::assertSame(['draft', 'ready', 'blocked'], $tools['nhk.media.ingest']['inputSchema']['properties']['readiness']['enum']);
        self::assertSame(['supports', 'contradicts', 'qualifies'], $tools['nhk.evidence.ingest']['inputSchema']['properties']['relation']['enum']);
        self::assertSame(['PUBLIC', 'PRIVATE', 'HIDDEN'], $tools['nhk.source.ingest']['inputSchema']['properties']['visibility']['enum']);
        self::assertSame(['PUBLIC', 'PRIVATE', 'HIDDEN'], $tools['nhk.evidence.ingest']['inputSchema']['properties']['visibility']['enum']);
    }

    public function test_media_file_ingest_uses_a_file_parameter_and_declares_processing_controls(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        $schema = $tools['nhk.media.ingest']['inputSchema']['properties'];
        self::assertSame('object', $schema['file']['type']);
        self::assertStringContainsString('base64', $schema['file']['description']);
        self::assertArrayNotHasKey('data', $schema['file']['properties']);
        self::assertSame(1, $schema['max_width']['minimum']);
        self::assertSame(1200, $schema['max_width']['maximum']);
        self::assertSame(1200, $schema['max_height']['maximum']);
        self::assertSame(1, $schema['quality']['minimum']);
        self::assertSame(100, $schema['quality']['maximum']);
        self::assertSame(1, $schema['assets']['items']['properties']['wordpress_attachment_id']['minimum']);
        self::assertSame(['attachment_id'], $tools['nhk.media.attachment.get']['inputSchema']['required']);
        self::assertFalse($tools['nhk.media.attachment.get']['governed']);
    }

    public function test_managed_image_policy_caps_long_edge_at_1200_without_upscale_or_crop(): void
    {
        self::assertSame(1200, ConcreteWordPressMediaAttachmentIngestor::MAX_LONG_EDGE);
        self::assertSame(['width' => 1200, 'height' => 800], ConcreteWordPressMediaAttachmentIngestor::constrainDimensions(6000, 4000));
        self::assertSame(['width' => 1200, 'height' => 800], ConcreteWordPressMediaAttachmentIngestor::constrainDimensions(1200, 800));
    }

    public function test_media_file_ingest_routes_multipart_file_without_accepting_base64_payloads(): void
    {
        $ingestor = new class implements WordPressMediaAttachmentIngestor {
            public ?array $received = null;
            public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array
            {
                $this->received = [$file, $filename, $title, $maxWidth, $maxHeight, $quality];
                return ['attachment_id' => 77, 'canonical_url' => 'https://example.test/wp-content/uploads/anh-thu-image-a1b2c3d4.webp', 'filename' => 'anh-thu-image-a1b2c3d4.webp', 'mime' => 'image/webp', 'width' => 100, 'height' => 80, 'filesize' => 123, 'derivatives' => []];
            }
            public function read(int $attachmentId): ?array { return null; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class),
            new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class),
            $this->createMock(MediaAssetRepository::class),
            $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class),
            $this->createMock(KnowledgeRepository::class),
            $this->createMock(EvidenceRepository::class),
            null,
            $this->createMock(SourceRepository::class),
            null,
            null,
            $ingestor,
        );
        $transport = new McpTransport($read, new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => true, null, null, null, $ingestor);
        $path = tempnam(sys_get_temp_dir(), 'nhk-mcp-file-');
        self::assertIsString($path);
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.media.ingest', 'arguments' => ['name' => 'Ảnh thử', 'filename' => 'Ảnh mặt tiền.JPG', 'max_width' => 1200, 'max_height' => 1200, 'quality' => 84]]];
        try {
            $response = $transport->dispatch($request, [], ['file' => ['tmp_name' => $path, 'name' => 'IMG_0001.JPG', 'type' => 'image/jpeg', 'size' => 10, 'error' => UPLOAD_ERR_OK]]);
            self::assertSame(200, $response['status']);
            self::assertSame(77, $response['body']['result']['structuredContent']['attachment_id']);
            self::assertIsArray($ingestor->received);
            self::assertSame($path, $ingestor->received[0]['tmp_name']);
            self::assertSame('Ảnh mặt tiền.JPG', $ingestor->received[1]);
            self::assertSame(1200, $ingestor->received[3]);
            self::assertSame(1200, $ingestor->received[4]);
            self::assertSame(84, $ingestor->received[5]);
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    public function test_wordpress_ability_allowlist_covers_the_catalog(): void
    {
        self::assertSame([
            'nhk-v3/documentation-bootstrap',
            'nhk-v3/documentation-get',
            'nhk-v3/documentation-list',
            'nhk-v3/docs-bootstrap',
            'nhk-v3/docs-get',
            'nhk-v3/search',
            'nhk-v3/canonical-inventory',
            'nhk-v3/graph-inventory',
            'nhk-v3/relation-backfill-dry-run',
            'nhk-v3/semantic-resolve',
            'nhk-v3/entity-neighborhood',
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
        self::assertSame('nhk-v3/docs-bootstrap', McpAbilityRegistration::abilityNameForTool('nhk.docs.bootstrap'));
        self::assertSame('nhk-v3/docs-get', McpAbilityRegistration::abilityNameForTool('nhk.docs.get'));
        self::assertContains('nhk-v3/docs-bootstrap', McpAbilityRegistration::readAbilityNames());
        self::assertContains('nhk-v3/docs-get', McpAbilityRegistration::readAbilityNames());
        self::assertArrayNotHasKey('nhk.docs.bootstrap', McpAbilityRegistration::explicitExclusionReasons());
        self::assertArrayNotHasKey('nhk.docs.get', McpAbilityRegistration::explicitExclusionReasons());
        self::assertSame('nhk-v3/video-ingest', McpAbilityRegistration::abilityNameForTool('nhk.video.ingest'));
        self::assertSame([
            'nhk-v3/public-url-reproject',
            'nhk-v3/article-ingest',
            'nhk-v3/capture-ingest',
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
            'nhk-v3/media-ingest',
            'nhk-v3/media-upload-batch',
            'nhk-v3/knowledge-ingest',
            'nhk-v3/source-ingest',
            'nhk-v3/evidence-ingest',
            'nhk-v3/proposal-create',
            'nhk-v3/proposal-submit',
            'nhk-v3/proposal-approve',
            'nhk-v3/proposal-reject',
            'nhk-v3/proposal-apply',
            'nhk-v3/relation-backfill-apply',
        ], McpAbilityRegistration::governedAbilityNames());
        self::assertSame('nhk-v3/article-preflight', McpAbilityRegistration::abilityNameForTool('nhk.article.preflight'));
        self::assertSame('nhk-v3/article-ingest', McpAbilityRegistration::abilityNameForTool('nhk.article.ingest'));
        self::assertCount(count(McpToolCatalog::tools()) - count(McpAbilityRegistration::explicitExclusionReasons()), McpAbilityRegistration::abilityNames());
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
        self::assertFalse($tools['nhk.proposal.eligibility']['governed']);
        self::assertSame('nhk-v3/proposal-eligibility', McpAbilityRegistration::abilityNameForTool('nhk.proposal.eligibility'));
        self::assertNotContains('nhk-v3/proposal-eligibility', McpAbilityRegistration::readAbilityNames());
        self::assertNotContains('nhk-v3/proposal-eligibility', McpAbilityRegistration::governedAbilityNames());
        self::assertContains('nhk-v3/proposal-eligibility', McpAbilityRegistration::capabilityGatedReadAbilityNames());
    }

    public function test_media_ingest_is_exposed_as_the_governed_wordpress_ability_for_attachment_metadata(): void
    {
        self::assertSame('nhk-v3/media-ingest', McpAbilityRegistration::abilityNameForTool('nhk.media.ingest'));
        self::assertContains('nhk-v3/media-ingest', McpAbilityRegistration::governedAbilityNames());
        self::assertArrayNotHasKey('nhk.media.ingest', McpAbilityRegistration::explicitExclusionReasons());
    }

    public function test_multipart_batch_upload_is_exposed_as_a_file_capable_ability(): void
    {
        self::assertSame('nhk-v3/media-upload-batch', McpAbilityRegistration::abilityNameForTool('nhk.media.upload-batch'));
        self::assertContains('nhk-v3/media-upload-batch', McpAbilityRegistration::governedAbilityNames());
        self::assertArrayNotHasKey('nhk.media.upload-batch', McpAbilityRegistration::explicitExclusionReasons());

        $tool = array_column(McpToolCatalog::tools(), null, 'name')['nhk.media.upload-batch'];
        self::assertArrayHasKey('files', $tool['inputSchema']['properties']);
        self::assertSame('array', $tool['inputSchema']['properties']['files']['type']);
        self::assertSame('binary', $tool['inputSchema']['properties']['files']['items']['format']);
    }

    public function test_editorial_capture_is_governed_and_accepts_text_only_or_multipart_input(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        $tool = $tools['nhk.capture.ingest'];
        self::assertSame('mutation', $tool['kind']);
        self::assertTrue($tool['governed']);
        self::assertSame(['idempotency_key', 'documentation_checkpoint'], $tool['inputSchema']['required']);
        self::assertArrayHasKey('capture_id', $tool['inputSchema']['properties']);
        self::assertSame('string', $tool['inputSchema']['properties']['capture_id']['type']);
        self::assertSame('uuid', $tool['inputSchema']['properties']['capture_id']['format']);
        self::assertSame('array', $tool['inputSchema']['properties']['files']['type']);
        self::assertSame('binary', $tool['inputSchema']['properties']['files']['items']['format']);
        self::assertSame('nhk-v3/capture-ingest', McpAbilityRegistration::abilityNameForTool('nhk.capture.ingest'));
    }

    public function test_capture_export_declares_native_file_rewrite_metadata_without_changing_text_contract(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        $capture = $tools['nhk.capture.ingest'];
        $files = $capture['inputSchema']['properties']['files'];

        self::assertSame(['files'], $capture['connectorMeta']['openai/fileParams']);
        self::assertSame(['idempotency_key', 'documentation_checkpoint'], $capture['inputSchema']['required']);
        self::assertNotContains('files', $capture['inputSchema']['required']);
        self::assertSame('array', $files['type']);
        self::assertSame('object', $files['items']['type']);
        self::assertSame('binary', $files['items']['format']);
        self::assertSame(20, $files['maxItems']);
        self::assertArrayNotHasKey('data', $files['items']);
        self::assertArrayNotHasKey('path', $files['items']);
        self::assertArrayNotHasKey('content_base64', $files['items']);

        $transport = new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())));
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $exported = array_column($response['body']['result']['tools'], null, 'name')['nhk.capture.ingest'];

        self::assertSame(['files'], $exported['_meta']['openai/fileParams']);
    }

    public function test_easy_mcp_does_not_auto_enable_standalone_media_uploaders(): void
    {
        $enabled = McpAbilityRegistration::ensureEasyMcpEnabledAbilities(['nhk-v3/video-ingest']);
        self::assertNotContains('nhk-v3/media-upload-batch', $enabled);
        self::assertNotContains('nhk-v3/media-ingest', $enabled);
        self::assertContains('nhk-v3/capture-ingest', $enabled);
    }

    public function test_easy_mcp_reconciles_stale_internal_writers_to_the_operator_allowlist(): void
    {
        $enabled = McpAbilityRegistration::ensureEasyMcpEnabledAbilities([
            'core/get-site-info',
            'nhk-v3/video-ingest',
            'nhk-v3/article-publish',
            'nhk-v3/search',
        ]);

        self::assertContains('core/get-site-info', $enabled);
        self::assertContains('nhk-v3/search', $enabled);
        self::assertContains('nhk-v3/capture-ingest', $enabled);
        self::assertContains('nhk-v3/documentation-bootstrap', $enabled);
        self::assertContains('nhk-v3/documentation-get', $enabled);
        self::assertContains('nhk-v3/documentation-list', $enabled);
        self::assertNotContains('nhk-v3/video-ingest', $enabled);
        self::assertNotContains('nhk-v3/article-publish', $enabled);
        self::assertSame($enabled, McpAbilityRegistration::ensureEasyMcpEnabledAbilities($enabled));
    }

    public function test_empty_easy_mcp_option_gets_the_canonical_operator_surface(): void
    {
        $enabled = McpAbilityRegistration::ensureEasyMcpEnabledAbilities([]);
        self::assertSame(McpAbilityRegistration::operatorEnabledAbilityAllowlist(), $enabled);
        self::assertContains('nhk-v3/capture-ingest', $enabled);
        self::assertContains('nhk-v3/documentation-bootstrap', $enabled);
        self::assertContains('nhk-v3/documentation-get', $enabled);
        self::assertContains('nhk-v3/documentation-list', $enabled);
        foreach (SingleEntryPointPolicy::internalOnlyTools() as $tool) {
            self::assertNotContains(McpAbilityRegistration::abilityNameForTool($tool), $enabled);
        }
    }

    public function test_easy_mcp_generic_whitelist_reconciles_to_safe_operator_patterns(): void
    {
        $expected = [
            'wp_ability_core_*',
            'wp_ability_nhk_v3_*',
            'wp_get_*',
            'wp_list_*',
            'wp_search_*',
            'wp_count_*',
        ];

        self::assertSame($expected, McpAbilityRegistration::canonicalEasyMcpAllowedToolPatterns());
        self::assertSame($expected, McpAbilityRegistration::ensureEasyMcpAllowedToolPatterns([]));
        self::assertSame($expected, McpAbilityRegistration::ensureEasyMcpAllowedToolPatterns(['*', 'wp_create_post', 'wp_upload_media']));
        self::assertSame($expected, McpAbilityRegistration::ensureEasyMcpAllowedToolPatterns($expected));
    }

    public function test_easy_mcp_generic_whitelist_preserves_reads_but_excludes_content_mutations(): void
    {
        $patterns = McpAbilityRegistration::canonicalEasyMcpAllowedToolPatterns();

        self::assertContains('wp_get_*', $patterns);
        self::assertContains('wp_list_*', $patterns);
        self::assertContains('wp_search_*', $patterns);
        self::assertContains('wp_count_*', $patterns);
        self::assertNotContains('wp_create_post', $patterns);
        self::assertNotContains('wp_update_post', $patterns);
        self::assertNotContains('wp_upload_media', $patterns);
        self::assertNotContains('wp_publish_post', $patterns);
        self::assertSame($patterns, McpAbilityRegistration::ensureEasyMcpAllowedToolPatterns($patterns));
    }

    public function test_documentation_abilities_use_the_read_capability_and_read_only_annotations(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');

        self::assertSame('read', $tools['nhk.docs.bootstrap']['kind'] === 'read' ? 'read' : null);
        self::assertSame('read', $tools['nhk.docs.get']['kind'] === 'read' ? 'read' : null);
        self::assertFalse($tools['nhk.docs.bootstrap']['governed']);
        self::assertFalse($tools['nhk.docs.get']['governed']);
        self::assertSame([], $tools['nhk.docs.bootstrap']['inputSchema']['required']);
        self::assertSame(McpDocumentationRegistry::documentKeys(), $tools['nhk.docs.get']['inputSchema']['properties']['document_key']['enum']);
        self::assertSame('read', $tools['nhk.documentation.bootstrap']['kind']);
        self::assertSame('read', $tools['nhk.documentation.get']['kind']);
        self::assertSame('read', $tools['nhk.documentation.list']['kind']);
    }
}
