<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Migration\V2MigrationService;
use NHK\Core\Infrastructure\Migration\MigrationLedger006;
use NHK\Core\Infrastructure\Migration\KnowledgeMigration005;
use NHK\Core\Infrastructure\Migration\KnowledgeEvidenceMetadataMigration007;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class V2MigrationIntegrationTest extends TestCase
{
    private const UUID = '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        (new MigrationLedger006())->down(true);
        (new MigrationLedger006())->up();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        $posts = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s", '_nhk_v2_source_key', 'wp_post:990001'));
        foreach ($posts as $postId) wp_delete_post((int) $postId, true);
        $posts = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s", '_nhk_v2_source_key', 'wp_post:990002'));
        foreach ($posts as $postId) wp_delete_post((int) $postId, true);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_entities WHERE stable_key LIKE %s", 'v2-migration-integration-%'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key LIKE %s", 'v2-migration-integration-%'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key=%s", 'wp_post:990001'));
    }

    public function test_apply_is_resumable_idempotent_and_reason_coded(): void
    {
        global $wpdb;
        $service = new V2MigrationService($wpdb);
        $records = [
            [
                'type' => 'brand',
                'stable_key' => 'v2-migration-integration-brand',
                'canonical_uuid' => self::UUID,
                'canonical_name' => 'Migration Fixture Brand',
                'metadata' => ['description' => 'Fixture only.', 'private_notes' => 'must not persist'],
            ],
            [
                'type' => 'wp_post',
                'stable_key' => 'wp_post:990001',
                'legacy_id' => '990001',
                'legacy_type' => 'nhk_article',
                'status' => 'publish',
                'post_name' => 'migration-fixture',
                'post_title' => 'Migration Fixture',
                'post_content' => 'Native editorial body.',
            ],
            [
                'type' => 'legacy_projection',
                'stable_key' => 'v2-migration-integration-unsupported',
            ],
        ];

        $first = $service->apply($records, 7, 10);
        self::assertSame(3, $first['processed']);
        self::assertSame(2, $first['migrated']);
        self::assertSame(1, $first['skipped']);
        self::assertSame(0, $first['conflict']);

        $second = $service->apply($records, 8, 10);
        self::assertSame(3, $second['processed']);
        self::assertSame(2, $second['migrated']);
        self::assertSame(1, $second['skipped']);
        self::assertSame(0, $second['conflict']);
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}nhk_entities WHERE stable_key=%s", 'v2-migration-integration-brand')));
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key=%s", 'v2-migration-integration-brand')));
        self::assertSame('UNSUPPORTED_LEGACY_TYPE', (string) $wpdb->get_var($wpdb->prepare("SELECT reason_code FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key=%s", 'v2-migration-integration-unsupported')));
        self::assertSame([], json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT payload FROM {$wpdb->prefix}nhk_entities WHERE stable_key=%s", 'v2-migration-integration-brand')), true)['private_notes'] ?? []);
    }

    public function test_source_and_citation_evidence_migrate_only_with_governed_endpoints(): void
    {
        global $wpdb;
        (new KnowledgeMigration005())->up();
        (new KnowledgeEvidenceMetadataMigration007())->up();
        (new MigrationLedger006())->up();
        self::assertSame(7, (int) get_option('nhk_core_migration_current'));
        $claimId = UuidCodec::newV7(); $sourceId = UuidCodec::newV7(); $evidenceId = UuidCodec::newV7();
        $records = [
            ['type' => 'knowledge', 'stable_key' => 'v2-migration-integration-claim', 'canonical_uuid' => $claimId, 'canonical_name' => 'Migration claim', 'metadata' => ['one_sentence_definition' => 'A migrated claim.']],
            ['type' => 'source', 'stable_key' => 'v2:evidence:' . $sourceId, 'canonical_uuid' => $sourceId, 'legacy_type' => 'OFFICIAL_ARCHIVE', 'canonical_name' => 'Migration source', 'locator' => 'https://example.com/source', 'visibility' => 'PRIVATE', 'metadata' => ['verification_state' => 'VERIFIED']],
            ['type' => 'evidence', 'stable_key' => 'v2:citation:' . $evidenceId, 'legacy_id' => '77', 'canonical_uuid' => $evidenceId, 'source_id' => $sourceId, 'claim_id' => $claimId, 'citation_role' => 'PRIMARY_SUPPORT', 'excerpt' => 'A bounded citation excerpt.', 'visibility' => 'PRIVATE', 'verification_state' => 'VERIFIED', 'excerpt_metadata' => '{"page":7}'],
            ['type' => 'evidence', 'stable_key' => 'v2:citation-missing', 'canonical_uuid' => UuidCodec::newV7(), 'source_id' => UuidCodec::newV7(), 'claim_id' => $claimId, 'citation_role' => 'PRIMARY_SUPPORT', 'excerpt' => 'No source endpoint.'],
        ];
        $result = (new V2MigrationService($wpdb))->apply($records, 9, 10);
        self::assertSame(4, $result['processed']); self::assertSame(3, $result['migrated']); self::assertSame(1, $result['skipped']);
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}nhk_sources WHERE canonical_uuid=%s", UuidCodec::toBinary($sourceId))));
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}nhk_evidence WHERE evidence_uuid=%s", UuidCodec::toBinary($evidenceId))));
        self::assertSame(['verification_state' => 'VERIFIED', 'visibility' => 'PRIVATE', 'excerpt_metadata' => '{"page":7}', 'legacy_id' => '77'], json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT metadata_json FROM {$wpdb->prefix}nhk_evidence WHERE evidence_uuid=%s", UuidCodec::toBinary($evidenceId))), true));
        self::assertSame('MISSING_ENDPOINT', (string) $wpdb->get_var($wpdb->prepare("SELECT reason_code FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key=%s", 'v2:citation-missing')));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_evidence WHERE evidence_uuid=%s", UuidCodec::toBinary($evidenceId)));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_sources WHERE canonical_uuid=%s", UuidCodec::toBinary($sourceId)));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_knowledge_claims WHERE canonical_uuid=%s", UuidCodec::toBinary($claimId)));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key LIKE %s", 'v2-migration-integration-%'));
    }

    public function test_url_mapping_requires_a_governed_target_and_allows_safe_noop(): void
    {
        global $wpdb;
        $records = [
            ['type' => 'url', 'stable_key' => 'v2-migration-integration-url-safe', 'source_path' => '/tim-kiem/', 'target_path' => '/tim-kiem/'],
            ['type' => 'url', 'stable_key' => 'v2-migration-integration-url-unmapped', 'source_path' => '/legacy/custom/', 'target_path' => ''],
        ];
        $result = (new V2MigrationService($wpdb))->apply($records, 10, 10);
        self::assertSame(2, $result['processed']); self::assertSame(1, $result['migrated']); self::assertSame(1, $result['skipped']); self::assertSame(0, $result['conflict']);
        self::assertSame('READY_NOOP', (string) $wpdb->get_var($wpdb->prepare("SELECT reason_code FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key=%s", 'v2-migration-integration-url-safe')));
        self::assertSame('INVALID_URL_MAPPING', (string) $wpdb->get_var($wpdb->prepare("SELECT reason_code FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key=%s", 'v2-migration-integration-url-unmapped')));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key LIKE %s", 'v2-migration-integration-url-%'));
    }

    public function test_url_mapping_persists_native_post_redirect_alias(): void
    {
        global $wpdb;
        $records = [
            ['type' => 'wp_post', 'stable_key' => 'wp_post:990002', 'legacy_id' => '990002', 'legacy_type' => 'nhk_article', 'status' => 'publish', 'post_name' => 'migration-redirect-fixture', 'post_title' => 'Migration Redirect Fixture', 'post_content' => 'Native editorial body.'],
            ['type' => 'url', 'stable_key' => 'v2-migration-integration-url-redirect', 'legacy_id' => '990002', 'source_path' => '/tri-thuc/migration-redirect-fixture/', 'target_path' => '/migration-redirect-fixture/'],
        ];
        $result = (new V2MigrationService($wpdb))->apply($records, 11, 10);
        self::assertSame(2, $result['processed']); self::assertSame(2, $result['migrated']); self::assertSame(0, $result['skipped']);
        $postId = (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key=%s AND meta_value=%s LIMIT 1", '_nhk_v2_source_key', 'wp_post:990002'));
        self::assertGreaterThan(0, $postId);
        self::assertSame('/tri-thuc/migration-redirect-fixture/', get_post_meta($postId, '_nhk_v2_redirect_path', true));
        self::assertSame('READY', (string) $wpdb->get_var($wpdb->prepare("SELECT reason_code FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key=%s", 'v2-migration-integration-url-redirect')));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key LIKE %s", 'v2-migration-integration-url-redirect'));
    }
}
