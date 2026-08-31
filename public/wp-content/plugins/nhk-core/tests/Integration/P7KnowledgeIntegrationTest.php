<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Migration\{KnowledgeEvidenceMetadataMigration007, KnowledgeMigration005};
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class P7KnowledgeIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        (new KnowledgeMigration005())->down();
        (new KnowledgeMigration005())->up();
        (new KnowledgeEvidenceMetadataMigration007())->up();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        $wpdb->query("DELETE FROM {$wpdb->prefix}nhk_evidence WHERE claim_uuid IN (SELECT canonical_uuid FROM {$wpdb->prefix}nhk_knowledge_claims WHERE stable_key LIKE 'p7-integration-%')");
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_knowledge_claims WHERE stable_key LIKE %s", 'p7-integration-%'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_sources WHERE stable_key LIKE %s", 'p7-integration-%'));
    }

    public function test_migration_is_idempotent_and_service_persists_claim_source_evidence(): void
    {
        global $wpdb;
        $migration = new KnowledgeMigration005();
        $migration->up();
        foreach (['nhk_knowledge_claims', 'nhk_sources', 'nhk_evidence'] as $table) self::assertSame($wpdb->prefix . $table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $table)));
        $service = new KnowledgeService(new WpdbKnowledgeRepository($wpdb), new WpdbSourceRepository($wpdb), new WpdbEvidenceRepository($wpdb));
        $claim = $service->createClaim('p7-integration-claim', 'The clock uses a spring-driven movement.', 'technical', ['source' => 'test']);
        $source = $service->createSource('p7-integration-source', 'Integration catalogue', 'catalog', 'catalog:page-1');
        $evidence = $service->cite($claim->canonicalId, $source->canonicalId, 'Spring-driven movement', 'supports');
        self::assertSame($claim->canonicalId, $evidence->claimId);
        self::assertCount(1, $service->evidenceForClaim($claim->canonicalId));
        self::assertSame($claim->canonicalId, $service->createClaim('p7-integration-claim', 'The clock uses a spring-driven movement.', 'technical', ['source' => 'test'])->canonicalId);
    }
}
