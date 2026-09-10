<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpToolCatalog, SingleEntryPointPolicy};
use PHPUnit\Framework\TestCase;

final class McpGovernanceQueueExposureTest extends TestCase
{
    public function test_admin_queue_does_not_change_operator_catalog_or_capture_entry_point(): void
    {
        $names = array_column(McpToolCatalog::tools(), 'name');

        self::assertSame([
            'nhk.documentation.bootstrap', 'nhk.documentation.get', 'nhk.documentation.list',
            'nhk.docs.bootstrap', 'nhk.docs.get', 'nhk.search', 'nhk.canonical.inventory',
            'nhk.graph.inventory', 'nhk.relation.backfill.dry_run', 'nhk.relation.backfill.apply',
            'nhk.semantic.resolve', 'nhk.entity.neighborhood', 'nhk.article.preflight',
            'nhk.article.ingest', 'nhk.capture.ingest', 'nhk.category.resolve', 'nhk.category.create',
            'nhk.category.update', 'nhk.category.assign', 'nhk.category.unassign', 'nhk.category.delete',
            'nhk.article.draft.create', 'nhk.article.draft.update', 'nhk.article.publish',
            'nhk.article.publish.review', 'nhk.article.publish.approve', 'nhk.article.trash',
            'nhk.article.restore', 'nhk.entity.get', 'nhk.media.get', 'nhk.media.upload-batch',
            'nhk.media.ingest', 'nhk.media.attachment.get', 'nhk.video.ingest', 'nhk.video.get',
            'nhk.knowledge.get', 'nhk.source.get', 'nhk.evidence.get', 'nhk.knowledge.ingest',
            'nhk.source.ingest', 'nhk.evidence.ingest', 'nhk.public-url.audit', 'nhk.public-url.reproject',
            'nhk.proposal.create', 'nhk.proposal.submit', 'nhk.proposal.review', 'nhk.proposal.approve',
            'nhk.proposal.reject', 'nhk.proposal.eligibility', 'nhk.proposal.apply',
        ], $names);

        self::assertContains(SingleEntryPointPolicy::CANONICAL_TOOL, $names);
        self::assertNotContains('nhk.governance.queue', $names);
        self::assertNotContains('nhk.admin.governance', $names);
        self::assertTrue(SingleEntryPointPolicy::isInternalOnly('nhk.proposal.apply'));
        self::assertSame('canonical', SingleEntryPointPolicy::surface(SingleEntryPointPolicy::CANONICAL_TOOL));
    }

    public function test_easy_mcp_operator_allowlist_remains_unchanged_and_excludes_internal_writers(): void
    {
        self::assertSame([
            'nhk-v3/documentation-bootstrap', 'nhk-v3/documentation-get', 'nhk-v3/documentation-list',
            'nhk-v3/docs-bootstrap', 'nhk-v3/docs-get', 'nhk-v3/search', 'nhk-v3/canonical-inventory',
            'nhk-v3/graph-inventory', 'nhk-v3/relation-backfill-dry-run', 'nhk-v3/semantic-resolve',
            'nhk-v3/entity-neighborhood', 'nhk-v3/article-preflight', 'nhk-v3/capture-ingest',
            'nhk-v3/category-resolve', 'nhk-v3/entity-get', 'nhk-v3/media-get', 'nhk-v3/media-attachment-get',
            'nhk-v3/video-get', 'nhk-v3/knowledge-get', 'nhk-v3/source-get', 'nhk-v3/evidence-get',
            'nhk-v3/public-url-audit', 'nhk-v3/proposal-review', 'nhk-v3/proposal-eligibility',
        ], McpAbilityRegistration::operatorEnabledAbilityAllowlist());
        self::assertSame([
            'wp_ability_core_*', 'wp_ability_nhk_v3_*', 'wp_get_*', 'wp_list_*', 'wp_search_*', 'wp_count_*',
        ], McpAbilityRegistration::canonicalEasyMcpAllowedToolPatterns());

        foreach (SingleEntryPointPolicy::internalOnlyTools() as $tool) {
            self::assertNotContains(McpAbilityRegistration::abilityNameForTool($tool), McpAbilityRegistration::operatorEnabledAbilityAllowlist());
        }
    }

    public function test_queue_sources_do_not_register_mcp_or_add_a_generic_writer(): void
    {
        $directory = dirname(__DIR__, 2) . '/src/Infrastructure/Admin';
        $paths = glob($directory . '/GovernanceQueue*.php') ?: [];
        self::assertNotEmpty($paths);

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            self::assertStringNotContainsString('McpToolCatalog', $source, $path);
            self::assertStringNotContainsString('McpAbilityRegistration', $source, $path);
            self::assertDoesNotMatchRegularExpression('/\bwp_(?:insert|update|delete|create|publish)(?:_|\s*\()/i', $source, $path);
            self::assertDoesNotMatchRegularExpression('/\b(?:INSERT\s+INTO|UPDATE\s+[^\n]+\s+SET|DELETE\s+FROM)\b/i', $source, $path);
        }
    }
}
