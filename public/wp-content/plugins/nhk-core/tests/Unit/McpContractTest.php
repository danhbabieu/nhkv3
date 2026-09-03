<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpToolCatalog;
use NHK\Core\Application\Mcp\McpAbilityRegistration;
use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpReadHandler, McpTransport};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Contracts\Media\WordPressMediaAttachmentIngestor;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Infrastructure\Media\WordPressMediaAttachmentIngestor as ConcreteWordPressMediaAttachmentIngestor;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class McpContractTest extends TestCase
{
    public function test_catalog_has_exact_current_ordered_tool_contract(): void
    {
        self::assertSame([
            'nhk.search',
            'nhk.semantic.resolve',
            'nhk.article.preflight',
            'nhk.article.ingest',
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
            'nhk.proposal.create',
            'nhk.proposal.submit',
            'nhk.proposal.review',
            'nhk.proposal.approve',
            'nhk.proposal.reject',
            'nhk.proposal.eligibility',
            'nhk.proposal.apply',
        ], array_column(McpToolCatalog::tools(), 'name'));
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
        self::assertSame(2048, $schema['max_width']['maximum']);
        self::assertSame(1, $schema['quality']['minimum']);
        self::assertSame(100, $schema['quality']['maximum']);
        self::assertSame(['attachment_id'], $tools['nhk.media.attachment.get']['inputSchema']['required']);
        self::assertFalse($tools['nhk.media.attachment.get']['governed']);
    }

    public function test_managed_image_policy_caps_long_edge_at_2048_without_upscale_or_crop(): void
    {
        self::assertSame(2048, ConcreteWordPressMediaAttachmentIngestor::MAX_LONG_EDGE);
        self::assertSame(['width' => 2048, 'height' => 1365], ConcreteWordPressMediaAttachmentIngestor::constrainDimensions(6000, 4000));
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
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.media.ingest', 'arguments' => ['name' => 'Ảnh thử', 'filename' => 'Ảnh mặt tiền.JPG', 'max_width' => 1600, 'max_height' => 1200, 'quality' => 84]]];
        try {
            $response = $transport->dispatch($request, [], ['file' => ['tmp_name' => $path, 'name' => 'IMG_0001.JPG', 'type' => 'image/jpeg', 'size' => 10, 'error' => UPLOAD_ERR_OK]]);
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
        self::assertFalse($tools['nhk.proposal.eligibility']['governed']);
        self::assertSame('nhk-v3/proposal-eligibility', McpAbilityRegistration::abilityNameForTool('nhk.proposal.eligibility'));
        self::assertNotContains('nhk-v3/proposal-eligibility', McpAbilityRegistration::readAbilityNames());
        self::assertNotContains('nhk-v3/proposal-eligibility', McpAbilityRegistration::governedAbilityNames());
        self::assertContains('nhk-v3/proposal-eligibility', McpAbilityRegistration::capabilityGatedReadAbilityNames());
    }

    public function test_multipart_media_ingest_is_explicitly_excluded_from_ability_transport(): void
    {
        self::assertNull(McpAbilityRegistration::abilityNameForTool('nhk.media.ingest'));
        self::assertSame(
            'multipart canonical transport; WordPress Ability input cannot carry the file part',
            McpAbilityRegistration::explicitExclusionReasons()['nhk.media.ingest']
        );
    }
}
