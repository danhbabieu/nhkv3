<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Migration\V2MigrationService;
use NHK\Core\Infrastructure\Migration\MigrationLedger006;
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
}
