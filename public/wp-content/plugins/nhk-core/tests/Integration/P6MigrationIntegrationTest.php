<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Infrastructure\Migration\MediaMigration004;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Media\{MediaAsset, MediaUsage};
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository};
use NHK\Core\Shared\Uuid\UuidCodec;
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

    public function test_media_asset_and_usage_repositories_resolve_canonical_media_uuid_at_storage_boundary(): void
    {
        global $wpdb;
        $media = new Media(UuidCodec::newV7(), 'integration-media-boundary-' . bin2hex(random_bytes(4)), 'Boundary Media', 'ready');
        $media = (new WpdbMediaRepository($wpdb))->create($media);
        $checksum = hash('sha256', 'media-boundary-fixture');
        $asset = (new WpdbMediaAssetRepository($wpdb))->create(new MediaAsset(UuidCodec::newV7(), $media->canonicalId, 'original', 'uploads/boundary.webp', $checksum, 'image/webp', 123, 10, 20));
        $usage = (new WpdbMediaUsageRepository($wpdb))->create(new MediaUsage(UuidCodec::newV7(), $media->canonicalId, 'wp_post', '1:987654', 'featured'));

        self::assertSame($media->canonicalId, $asset->mediaId);
        self::assertSame($media->canonicalId, (new WpdbMediaAssetRepository($wpdb))->listByMediaId($media->canonicalId)[0]->mediaId);
        self::assertSame($media->canonicalId, (new WpdbMediaUsageRepository($wpdb))->listByMediaId($media->canonicalId)[0]->mediaId);
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}nhk_media_assets WHERE media_id=%d", (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", UuidCodec::toBinary($media->canonicalId))))));

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_usages WHERE usage_uuid=%s", UuidCodec::toBinary($usage->usageId)));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_assets WHERE asset_uuid=%s", UuidCodec::toBinary($asset->assetId)));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", UuidCodec::toBinary($media->canonicalId)));
    }
}
