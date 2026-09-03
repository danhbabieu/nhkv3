<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Media\MediaService;
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository, WordPressMediaAttachmentBridge, WordPressMediaAttachmentIngestor};
use NHK\Core\Infrastructure\Migration\{MediaAssetMetadataMigration008, MediaMigration004};
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class WordPressMediaIngestIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        (new MediaMigration004())->up();
        (new MediaAssetMetadataMigration008())->up();
    }

    public function test_real_file_ingest_retains_source_and_repeated_adoption_resolves_one_media(): void
    {
        global $wpdb;
        $source = tempnam(sys_get_temp_dir(), 'nhk-upload-');
        self::assertIsString($source);
        self::assertTrue(copy(ABSPATH . 'wp-admin/images/post-formats-vs.png', $source));
        $bridge = null;
        $attachmentId = 0;
        $mediaId = '';
        $sourceRelative = '';
        try {
            $media = new WpdbMediaRepository($wpdb);
            $assets = new WpdbMediaAssetRepository($wpdb);
            $usages = new WpdbMediaUsageRepository($wpdb);
            $service = new MediaService($media, $assets, $usages);
            $bridge = new WordPressMediaAttachmentBridge($wpdb, $service, $media, $assets);
            $result = (new WordPressMediaAttachmentIngestor($bridge))->ingest(
                ['error' => UPLOAD_ERR_OK, 'tmp_name' => $source],
                'source-original.png',
                'Integration source original',
                1200,
                1200,
                82
            );
            $attachmentId = (int) $result['attachment_id'];
            $mediaId = (string) ($result['media_id'] ?? '');
            self::assertNotSame('', $mediaId);
            self::assertSame($mediaId, $bridge->adoptAttachment($attachmentId));
            self::assertSame($mediaId, $bridge->adoptAttachment($attachmentId));
            self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($mediaId))));
            $mediaAssets = $assets->listByMediaId($mediaId);
            self::assertCount(2, $mediaAssets);
            self::assertContains('original', array_map(static fn ($asset): string => $asset->kind, $mediaAssets));
            self::assertContains('derivative', array_map(static fn ($asset): string => $asset->kind, $mediaAssets));
            self::assertContains('PUBLIC', array_map(static fn ($asset): string => $asset->visibility, $mediaAssets));
            $sourceRelative = (string) get_post_meta($attachmentId, '_nhk_source_original_file', true);
            self::assertNotSame('', $sourceRelative);
        } finally {
            if ($attachmentId > 0 && function_exists('wp_delete_attachment')) wp_delete_attachment($attachmentId, true);
            if ($mediaId !== '') {
                $internalId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($mediaId)));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_usages WHERE media_id=%d", $internalId));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_assets WHERE media_id=%d", $internalId));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE id=%d", $internalId));
            }
            if ($sourceRelative !== '' && function_exists('wp_upload_dir')) {
                $upload = wp_upload_dir();
                $baseDir = is_array($upload) ? (string) ($upload['basedir'] ?? '') : '';
                $sourcePath = $baseDir !== '' ? $baseDir . '/' . ltrim($sourceRelative, '/') : '';
                if ($sourcePath !== '' && is_file($sourcePath)) unlink($sourcePath);
            }
            if ($source !== false && is_file($source)) unlink($source);
        }
    }
}
