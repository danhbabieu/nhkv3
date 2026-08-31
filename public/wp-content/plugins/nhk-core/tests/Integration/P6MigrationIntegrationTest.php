<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Infrastructure\Migration\MediaMigration004;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class P6MigrationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
    }

    public function test_media_video_migration_is_idempotent_and_down_is_test_db_only(): void
    {
        global $wpdb;
        $migration = new MediaMigration004();
        $migration->down();
        $migration->up();
        $migration->up();
        foreach (['nhk_media', 'nhk_media_assets', 'nhk_media_usages', 'nhk_videos'] as $table) self::assertSame($wpdb->prefix . $table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $table)));
        self::assertSame(4, (int) get_option('nhk_core_migration_current'));
        self::assertSame(4, (int) get_option('nhk_core_migration_target'));
        $checksumType = $wpdb->get_var($wpdb->prepare("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name='checksum'", $wpdb->prefix . 'nhk_media_assets'));
        self::assertSame('binary(32)', strtolower((string) $checksumType));
    }
}
