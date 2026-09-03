<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpToolCatalog;
use NHK\Core\Application\Mcp\McpAbilityRegistration;
use PHPUnit\Framework\TestCase;

final class McpContractTest extends TestCase
{
    public function test_catalog_has_exact_current_ordered_twenty_one_tool_contract(): void
    {
        self::assertSame([
            'nhk.search',
            'nhk.semantic.resolve',
            'nhk.article.preflight',
            'nhk.article.ingest',
            'nhk.entity.get',
            'nhk.media.get',
            'nhk.media.ingest',
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

    public function test_media_ingest_declares_complete_nested_asset_and_usage_contracts(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        $schema = $tools['nhk.media.ingest']['inputSchema']['properties'];
        self::assertSame(['kind', 'storage_key', 'checksum', 'mime_type', 'byte_size'], $schema['assets']['items']['required']);
        self::assertFalse($schema['assets']['items']['additionalProperties']);
        self::assertSame('^[0-9A-Fa-f]{64}$', $schema['assets']['items']['properties']['checksum']['pattern']);
        self::assertSame(['endpoint_type', 'endpoint_key', 'role'], $schema['usages']['items']['required']);
        self::assertFalse($schema['usages']['items']['additionalProperties']);
        self::assertSame(['featured_primary', 'inline_primary', 'inline_supporting', 'featured', 'inline', 'gallery', 'thumbnail', 'source'], $schema['usages']['items']['properties']['role']['enum']);
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

    public function test_wordpress_ability_allowlist_contains_only_existing_read_tools(): void
    {
        self::assertSame([
            'nhk-v3/search',
            'nhk-v3/semantic-resolve',
            'nhk-v3/entity-get',
            'nhk-v3/media-get',
            'nhk-v3/video-get',
            'nhk-v3/knowledge-get',
            'nhk-v3/source-get',
            'nhk-v3/evidence-get',
        ], McpAbilityRegistration::readAbilityNames());
        self::assertSame('nhk-v3/entity-get', McpAbilityRegistration::abilityNameForTool('nhk.entity.get'));
        self::assertNull(McpAbilityRegistration::abilityNameForTool('nhk.media.ingest'));
        self::assertNull(McpAbilityRegistration::abilityNameForTool('nhk.article.preflight'));
        self::assertNull(McpAbilityRegistration::abilityNameForTool('nhk.article.ingest'));
    }
}
