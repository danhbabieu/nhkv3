<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpDispatchRegistry, McpToolCatalog};
use PHPUnit\Framework\TestCase;

final class McpDictionaryToolsContractTest extends TestCase
{
    public function test_dictionary_tools_have_catalog_dispatch_and_strict_mutation_fields(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        foreach (['nhk.dictionary.search', 'nhk.dictionary.resolve', 'nhk.dictionary.concept.get', 'nhk.dictionary.candidate.list', 'nhk.dictionary.candidate.get', 'nhk.dictionary.mentions.list', 'nhk.dictionary.concept.create', 'nhk.dictionary.concept.update', 'nhk.dictionary.concept.lifecycle', 'nhk.dictionary.label.save', 'nhk.dictionary.candidate.review', 'nhk.dictionary.relation.handoff', 'nhk.dictionary.backfill.dry_run', 'nhk.dictionary.profile', 'nhk.dictionary.enrichment.audit', 'nhk.dictionary.enrichment.plan', 'nhk.dictionary.enrichment.apply'] as $name) {
            self::assertArrayHasKey($name, $tools);
            self::assertTrue(McpDispatchRegistry::hasHandler($name));
        }
        self::assertSame('mutation', $tools['nhk.dictionary.concept.update']['kind']);
        self::assertContains('idempotency_key', $tools['nhk.dictionary.concept.update']['inputSchema']['required']);
        self::assertContains('expected_revision', $tools['nhk.dictionary.concept.update']['inputSchema']['required']);
        self::assertArrayNotHasKey('nhk.dictionary.concept.delete', $tools);
        self::assertSame('read', $tools['nhk.dictionary.enrichment.audit']['kind']);
        self::assertSame('mutation', $tools['nhk.dictionary.enrichment.apply']['kind']);
        self::assertContains('approved_plan_fingerprint', $tools['nhk.dictionary.enrichment.apply']['inputSchema']['required']);
    }
}
