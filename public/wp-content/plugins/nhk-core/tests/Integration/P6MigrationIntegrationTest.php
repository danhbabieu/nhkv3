<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Infrastructure\Migration\MediaMigration004;
use NHK\Core\Infrastructure\Migration\MediaAssetMetadataMigration008;
use NHK\Core\Application\Migration\V2MigrationService;
use NHK\Core\Application\Media\MediaVideoPageQuery;
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Domain\Video\Video;
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
        (new MediaAssetMetadataMigration008())->up();
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

    public function test_media_asset_visibility_schema_defaults_to_private(): void
    {
        global $wpdb;
        (new MediaAssetMetadataMigration008())->up();

        $default = $wpdb->get_var($wpdb->prepare(
            "SELECT column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name='visibility'",
            $wpdb->prefix . 'nhk_media_assets'
        ));

        self::assertSame('PRIVATE', strtoupper(trim((string) $default, "'")));
    }

    public function test_media_asset_repository_ignores_non_object_metadata_rows(): void
    {
        global $wpdb;
        (new MediaAssetMetadataMigration008())->up();
        $media = (new WpdbMediaRepository($wpdb))->create(new Media(UuidCodec::newV7(), 'integration-media-corrupt-metadata-' . bin2hex(random_bytes(4)), 'Corrupt metadata media', 'ready'));
        $repository = new WpdbMediaAssetRepository($wpdb);
        $asset = $repository->create(new MediaAsset(UuidCodec::newV7(), $media->canonicalId, 'original', 'corrupt/metadata.webp', hash('sha256', 'corrupt-metadata'), 'image/webp', 16, null, null, 'PRIVATE'));

        try {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}nhk_media_assets SET metadata_json=%s WHERE asset_uuid=%s",
                '"not-an-object"',
                UuidCodec::toBinary($asset->assetId)
            ));

            self::assertNull($repository->findByAssetId($asset->assetId));
            self::assertSame([], $repository->listByMediaId($media->canonicalId));
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_assets WHERE asset_uuid=%s", UuidCodec::toBinary($asset->assetId)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", UuidCodec::toBinary($media->canonicalId)));
        }
    }

    public function test_malformed_media_asset_is_skipped_with_invalid_identity_reason(): void
    {
        global $wpdb;
        (new MediaMigration004())->up();
        (new MediaAssetMetadataMigration008())->up();
        $media = (new WpdbMediaRepository($wpdb))->create(new Media(UuidCodec::newV7(), 'v2-migration-malformed-media-' . bin2hex(random_bytes(4)), 'Malformed Media', 'ready'));
        $sourceKey = 'v2-migration-malformed-media-asset-' . bin2hex(random_bytes(4));

        try {
            $result = (new V2MigrationService($wpdb))->apply([[
                'type' => 'legacy_media_asset',
                'stable_key' => $sourceKey,
                'media_id' => $media->canonicalId,
                'public_id' => 'legacy-asset-1',
                'storage_key' => 'uploads/malformed.jpg',
                'checksum' => hash('sha256', 'malformed-media-asset'),
                'mime_type' => '',
            ]], 21, 10);

            self::assertSame(1, $result['processed']);
            self::assertSame(0, $result['migrated']);
            self::assertSame(1, $result['skipped']);
            self::assertSame(0, $result['conflict']);
            self::assertSame('INVALID_IDENTITY', (string) $wpdb->get_var($wpdb->prepare(
                "SELECT reason_code FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key=%s",
                $sourceKey
            )));
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_migration_ledger WHERE source_key=%s", $sourceKey));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", UuidCodec::toBinary($media->canonicalId)));
        }
    }

    public function test_media_asset_repository_rejects_same_identity_with_changed_content(): void
    {
        global $wpdb;
        (new MediaMigration004())->up();
        (new MediaAssetMetadataMigration008())->up();
        $media = (new WpdbMediaRepository($wpdb))->create(new Media(UuidCodec::newV7(), 'integration-media-conflict-' . bin2hex(random_bytes(4)), 'Conflict Media', 'ready'));
        $repository = new WpdbMediaAssetRepository($wpdb);
        $asset = new MediaAsset(UuidCodec::newV7(), $media->canonicalId, 'original', 'uploads/conflict.jpg', hash('sha256', 'conflict-original'), 'image/jpeg', 42, 20, 10, 'PRIVATE', ['source' => 'test']);
        $repository->create($asset);

        try {
            $repository->create(new MediaAsset($asset->assetId, $asset->mediaId, 'original', $asset->storageKey, $asset->checksum, 'image/png', 42, 20, 10, 'PRIVATE', ['source' => 'test']));
            self::fail('Expected a same-identity Media asset with changed content metadata to be rejected.');
        } catch (\NHK\Core\Domain\Media\MediaException $exception) {
            self::assertSame('Media asset identity or storage key already exists.', $exception->getMessage());
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_assets WHERE asset_uuid=%s", UuidCodec::toBinary($asset->assetId)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", UuidCodec::toBinary($media->canonicalId)));
        }
    }

    public function test_private_asset_metadata_is_persisted_but_not_exposed_by_public_media_query(): void
    {
        global $wpdb;
        (new MediaAssetMetadataMigration008())->up();
        $media = (new WpdbMediaRepository($wpdb))->create(new Media(UuidCodec::newV7(), 'integration-media-visibility-' . bin2hex(random_bytes(4)), 'Visibility Media', 'ready'));
        $assetRepository = new WpdbMediaAssetRepository($wpdb);
        $asset = $assetRepository->create(new MediaAsset(UuidCodec::newV7(), $media->canonicalId, 'original', 'private/asset.webp', hash('sha256', 'private-asset'), 'image/webp', 42, 20, 10, 'PRIVATE', ['title' => 'Private source', 'status' => 'private']));

        self::assertSame('PRIVATE', $assetRepository->findByAssetId($asset->assetId)?->visibility);
        self::assertSame(['title' => 'Private source', 'status' => 'private'], $assetRepository->findByAssetId($asset->assetId)?->metadata);
        $query = new MediaVideoPageQuery(new WpdbMediaRepository($wpdb), $assetRepository, new WpdbMediaUsageRepository($wpdb), new WpdbVideoRepository($wpdb));
        self::assertSame([], $query->mediaDetail($media->canonicalId)['assets']);

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_assets WHERE asset_uuid=%s", UuidCodec::toBinary($asset->assetId)));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", UuidCodec::toBinary($media->canonicalId)));
    }

    public function test_media_usage_repository_rejects_same_endpoint_with_changed_sort_order(): void
    {
        global $wpdb;
        (new MediaMigration004())->up();
        $media = (new WpdbMediaRepository($wpdb))->create(new Media(UuidCodec::newV7(), 'integration-usage-conflict-' . bin2hex(random_bytes(4)), 'Usage Conflict Media', 'ready'));
        $repository = new WpdbMediaUsageRepository($wpdb);
        $usage = new MediaUsage(UuidCodec::newV7(), $media->canonicalId, 'wp_post', '1:987655', 'featured', 0);
        $repository->create($usage);

        try {
            $repository->create(new MediaUsage(UuidCodec::newV7(), $media->canonicalId, 'wp_post', '1:987655', 'featured', 1));
            self::fail('Expected a same-endpoint Media usage with changed sort order to be rejected.');
        } catch (\NHK\Core\Domain\Media\MediaException $exception) {
            self::assertSame('Media usage identity already exists.', $exception->getMessage());
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_usages WHERE usage_uuid=%s", UuidCodec::toBinary($usage->usageId)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", UuidCodec::toBinary($media->canonicalId)));
        }
    }

    public function test_media_repository_rejects_same_identity_with_changed_state(): void
    {
        global $wpdb;
        (new MediaMigration004())->up();
        $repository = new WpdbMediaRepository($wpdb);
        $media = new Media(UuidCodec::newV7(), 'integration-media-state-conflict-' . bin2hex(random_bytes(4)), 'State Conflict Media', 'ready', ['source' => 'test'], true, 1);
        $repository->create($media);

        try {
            $repository->create(new Media($media->canonicalId, $media->stableKey, $media->canonicalName, 'draft', $media->provenance, true, 1));
            self::fail('Expected a same-identity Media with changed readiness to be rejected.');
        } catch (\NHK\Core\Domain\Media\MediaException $exception) {
            self::assertSame('Media stable key or identity already exists.', $exception->getMessage());
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", UuidCodec::toBinary($media->canonicalId)));
        }
    }

    public function test_video_repository_is_idempotent_for_same_external_reference_and_rejects_changed_content(): void
    {
        global $wpdb;
        (new MediaMigration004())->up();
        $repository = new WpdbVideoRepository($wpdb);
        $externalId = substr(str_replace('-', '', UuidCodec::newV7()), 0, 11);
        $video = new Video(UuidCodec::newV7(), 'youtube', $externalId, 'https://www.youtube.com/watch?v=' . $externalId, 'Reference', ['source' => 'test']);
        $repository->create($video);
        $raced = $repository->create(new Video(UuidCodec::newV7(), $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $video->metadata));
        self::assertSame($video->canonicalId, $raced->canonicalId);

        try {
            $repository->create(new Video(UuidCodec::newV7(), $video->platform, $video->externalVideoId, $video->canonicalUrl, 'Changed reference', $video->metadata));
            self::fail('Expected a same-reference Video with changed content to be rejected.');
        } catch (\NHK\Core\Domain\Video\VideoException $exception) {
            self::assertSame('Video external reference or identity already exists.', $exception->getMessage());
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_videos WHERE canonical_uuid=%s", UuidCodec::toBinary($video->canonicalId)));
        }
    }
}
