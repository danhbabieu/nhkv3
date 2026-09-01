<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpToolCatalog;
use PHPUnit\Framework\TestCase;

final class McpContractTest extends TestCase
{
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
            self::assertSame('^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', $tools[$name]['inputSchema']['properties']['id']['pattern'], $name);
        }
        self::assertSame('^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', $tools['nhk.evidence.ingest']['inputSchema']['properties']['claim_id']['pattern']);
        self::assertSame('^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', $tools['nhk.evidence.ingest']['inputSchema']['properties']['source_id']['pattern']);
        self::assertArrayHasKey('subject_id', $tools['nhk.proposal.create']['inputSchema']['properties']);
        self::assertSame('^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', $tools['nhk.proposal.create']['inputSchema']['properties']['target_uuid']['pattern']);
        self::assertSame('^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', $tools['nhk.proposal.create']['inputSchema']['properties']['dependency_ids']['items']['pattern']);
        self::assertSame(1, $tools['nhk.proposal.create']['inputSchema']['properties']['expected_revision']['minimum']);
    }
}
