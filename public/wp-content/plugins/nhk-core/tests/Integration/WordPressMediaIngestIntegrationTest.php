<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Media\{ImageIngestEntrypoint, MediaBatchUploadService, MediaService, PublicImageSizingPolicy};
use NHK\Core\Domain\Media\Media;
use NHK\Core\Infrastructure\Media\{PrivateMediaSourceStorage, WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository, WordPressMediaAttachmentBridge, WordPressMediaAttachmentIngestor, WordPressMediaAttachmentWriteGuard};
use NHK\Core\Infrastructure\Mcp\ChatGptMcpGateway;
use NHK\Core\Infrastructure\Migration\{MediaAssetMetadataMigration008, MediaMigration004, MediaWordPressBridgeMigration012};
use NHK\Core\Shared\Uuid\UuidCodec;
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
        (new MediaWordPressBridgeMigration012())->up();
    }

    public function test_partial_mapped_raster_replay_fills_the_existing_media_without_duplicate_identity(): void
    {
        global $wpdb;
        $fixture = ABSPATH . 'wp-admin/images/post-formats-vs.png';
        $fixtureInfo = getimagesize($fixture);
        self::assertIsArray($fixtureInfo);
        $expectedDimensions = PublicImageSizingPolicy::constrain((int) $fixtureInfo[0], (int) $fixtureInfo[1]);
        $attachmentId = 0;
        $mediaId = UuidCodec::newV7();
        $sourceRelative = '';
        $publicPath = '';
        $uploadedPath = '';
        try {
            $media = new WpdbMediaRepository($wpdb);
            $assets = new WpdbMediaAssetRepository($wpdb);
            $service = new MediaService($media, $assets, new WpdbMediaUsageRepository($wpdb));
            $bridge = new WordPressMediaAttachmentBridge($wpdb, $service, $media, $assets);
            WordPressMediaAttachmentWriteGuard::enter();
            try {
                $upload = wp_upload_bits('nhk-partial-replay.png', null, (string) file_get_contents($fixture));
                self::assertIsArray($upload);
                self::assertEmpty($upload['error'] ?? null);
                $uploadedPath = (string) ($upload['file'] ?? '');
                self::assertNotSame('', $uploadedPath);
                $attachmentId = (int) wp_insert_attachment([
                    'post_mime_type' => 'image/png',
                    'post_title' => 'IMG_4571',
                    'post_content' => '',
                    'post_status' => 'inherit',
                ], $uploadedPath, 0, true);
                self::assertGreaterThan(0, $attachmentId);
                update_post_meta($attachmentId, '_wp_attached_file', _wp_relative_upload_path($uploadedPath));
                $metadata = wp_generate_attachment_metadata($attachmentId, $uploadedPath);
                self::assertIsArray($metadata);
                wp_update_attachment_metadata($attachmentId, $metadata);
            } finally {
                WordPressMediaAttachmentWriteGuard::leave();
            }

            $stableKey = 'wp-attachment:' . max(1, (int) get_current_blog_id()) . ':' . $attachmentId;
            $partial = $media->create(new Media($mediaId, $stableKey, 'Đồng hồ chim cúc cu — ảnh đại diện', 'draft', [
                'source' => 'wordpress_existing_attachment_url',
                'wordpress_attachment_id' => $attachmentId,
            ]));
            $mappingAssetId = UuidCodec::newV7();
            $now = gmdate('Y-m-d H:i:s.u');
            self::assertSame(1, (int) $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}nhk_media_wordpress_attachments (media_uuid,asset_uuid,attachment_id,storage_key,created_at,updated_at) VALUES (%s,%s,%d,%s,%s,%s)",
                UuidCodec::toBinary($partial->canonicalId), UuidCodec::toBinary($mappingAssetId), $attachmentId, 'pending', $now, $now,
            )));

            $context = [
                'canonical_name' => 'Đồng hồ chim cúc cu — ảnh đại diện',
                'seo_slug' => 'dong-ho-chim-cuc-cu-anh-dai-dien',
                'description' => 'Ảnh đại diện cho loại Đồng hồ chim cúc cu trên NHK.',
            ];
            self::assertSame($mediaId, $bridge->adoptAttachment($attachmentId, $context));
            self::assertSame($mediaId, $bridge->adoptAttachment($attachmentId, $context));

            $stored = $media->findByCanonicalId($mediaId);
            self::assertNotNull($stored);
            self::assertSame($stableKey, $stored?->stableKey);
            self::assertSame('ready', $stored?->readiness);
            $mediaAssets = $assets->listByMediaId($mediaId);
            self::assertCount(2, $mediaAssets);
            self::assertSame(1, count(array_filter($mediaAssets, static fn ($asset): bool => $asset->kind === 'original' && $asset->visibility === 'PRIVATE')));
            $derivatives = array_values(array_filter($mediaAssets, static fn ($asset): bool => $asset->kind === 'derivative'));
            self::assertCount(1, $derivatives);
            self::assertSame('PUBLIC', $derivatives[0]->visibility);
            self::assertSame('image/webp', $derivatives[0]->mimeType);
            self::assertSame('dong-ho-chim-cuc-cu-anh-dai-dien.webp', $derivatives[0]->metadata['canonical_filename'] ?? null);
            self::assertSame($expectedDimensions['width'], $derivatives[0]->width);
            self::assertSame($expectedDimensions['height'], $derivatives[0]->height);
            $sourceRelative = (string) (array_values(array_filter($mediaAssets, static fn ($asset): bool => $asset->kind === 'original'))[0]->storageKey ?? '');
            $configuredRoot = trim((string) (getenv('NHK_MEDIA_STORAGE_ROOT') ?: ''));
            $publicRoot = $configuredRoot !== '' ? $configuredRoot : (string) (wp_upload_dir()['basedir'] ?? '');
            $publicPath = rtrim($publicRoot, '/\\') . '/nhk-public/' . (string) ($derivatives[0]->metadata['canonical_filename'] ?? '');

            $mapping = $wpdb->get_var($wpdb->prepare("SELECT media_uuid FROM {$wpdb->prefix}nhk_media_wordpress_attachments WHERE attachment_id=%d", $attachmentId));
            self::assertSame($mediaId, is_string($mapping) && strlen($mapping) === 16 ? UuidCodec::fromBinary($mapping) : null);
            self::assertNotNull((new WordPressMediaAttachmentIngestor())->read($attachmentId));
        } finally {
            if ($attachmentId > 0 && function_exists('wp_delete_attachment')) wp_delete_attachment($attachmentId, true);
            if ($mediaId !== '') {
                $internalId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", UuidCodec::toBinary($mediaId)));
                if ($internalId > 0) {
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_usages WHERE media_id=%d", $internalId));
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_assets WHERE media_id=%d", $internalId));
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE id=%d", $internalId));
                }
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_wordpress_attachments WHERE media_uuid=%s", UuidCodec::toBinary($mediaId)));
            }
            if ($sourceRelative !== '' && str_starts_with($sourceRelative, 'private/')) try { PrivateMediaSourceStorage::fromWordPress()->delete($sourceRelative); } catch (\Throwable) { }
            if ($publicPath !== '' && is_file($publicPath)) @unlink($publicPath);
            if ($uploadedPath !== '' && is_file($uploadedPath)) @unlink($uploadedPath);
        }
    }

    public function test_real_file_ingest_retains_source_and_repeated_adoption_resolves_one_media(): void
    {
        global $wpdb;
        $source = tempnam(sys_get_temp_dir(), 'nhk-upload-');
        self::assertIsString($source);
        self::assertTrue(copy(ABSPATH . 'wp-admin/images/post-formats-vs.png', $source));
        $sourceInfo = getimagesize($source);
        self::assertIsArray($sourceInfo);
        $expectedDimensions = PublicImageSizingPolicy::constrain((int) $sourceInfo[0], (int) $sourceInfo[1]);
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
            self::assertSame('image/webp', (string) $result['mime']);
            self::assertGreaterThan(0, (int) $result['width']);
            self::assertGreaterThan(0, (int) $result['height']);
            self::assertGreaterThan(0, (int) $result['filesize']);
            self::assertStringEndsWith('.webp', (string) $result['filename']);
            self::assertNotSame('source-original.png', (string) $result['filename']);
            self::assertSame($expectedDimensions['width'], (int) $result['width']);
            self::assertSame($expectedDimensions['height'], (int) $result['height']);
            self::assertLessThanOrEqual(1200, max((int) $result['width'], (int) $result['height']));
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
            self::assertStringStartsWith('private/', $sourceRelative);
            self::assertNotNull(PrivateMediaSourceStorage::fromWordPress()->path($sourceRelative));
            self::assertSame('source-original.png', (string) get_post_meta($attachmentId, '_nhk_original_filename', true));
            $sourceAsset = array_values(array_filter($mediaAssets, static fn ($asset): bool => $asset->kind === 'original'))[0] ?? null;
            self::assertNotNull($sourceAsset);
            self::assertSame('source-original.png', $sourceAsset->metadata['original_filename'] ?? null);
        } finally {
            if ($attachmentId > 0 && function_exists('wp_delete_attachment')) wp_delete_attachment($attachmentId, true);
            if ($mediaId !== '') {
                $internalId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($mediaId)));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_usages WHERE media_id=%d", $internalId));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_assets WHERE media_id=%d", $internalId));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE id=%d", $internalId));
            }
            if ($sourceRelative !== '') try { PrivateMediaSourceStorage::fromWordPress()->delete($sourceRelative); } catch (\Throwable) { }
            if ($source !== false && is_file($source)) unlink($source);
        }
    }

    public function test_structured_reference_uses_gateway_materialization_then_native_attachment_adoption(): void
    {
        global $wpdb;
        $fixture = ABSPATH . 'wp-admin/images/post-formats-vs.png';
        $bridge = null;
        $attachmentId = 0;
        $mediaId = '';
        $sourceRelative = '';
        $idempotencyKey = 'structured-integration-' . bin2hex(random_bytes(8));
        try {
            $media = new WpdbMediaRepository($wpdb);
            $assets = new WpdbMediaAssetRepository($wpdb);
            $usages = new WpdbMediaUsageRepository($wpdb);
            $service = new MediaService($media, $assets, $usages);
            $bridge = new WordPressMediaAttachmentBridge($wpdb, $service, $media, $assets);
            $ingestor = new WordPressMediaAttachmentIngestor($bridge);
            $entrypoint = new ImageIngestEntrypoint(
                static fn (string $key, array $metadata, array $files, array $items): array => (new MediaBatchUploadService($ingestor))->upload($key, $metadata, $files, $items),
                static fn (mixed $references): array => ChatGptMcpGateway::materializeReferences(
                    $references,
                    static function (string $url, string $path, int $remaining) use ($fixture): array {
                        if ($url !== 'https://files.example.test/image' || !is_file($fixture) || filesize($fixture) > $remaining || !copy($fixture, $path)) return ['status' => 500];
                        return ['status' => 200];
                    },
                    static fn (string $host, string $url): bool => $host === 'files.example.test',
                ),
            );
            $manifest = $entrypoint->ingest($idempotencyKey, ['description' => 'Structured image'], [[
                'download_url' => 'https://files.example.test/image',
                'file_id' => 'structured-file-1',
                'mime_type' => 'image/png',
                'file_name' => 'IMG_4644.jpeg',
            ]], [['client_file_id' => 'structured-file-1']]);
            $item = $manifest['items'][0] ?? [];
            $attachmentId = (int) ($item['attachment_id'] ?? 0);
            $mediaId = (string) ($item['media_id'] ?? '');
            self::assertSame(1, $manifest['succeeded']);
            self::assertGreaterThan(0, $attachmentId);
            self::assertNotSame('', $mediaId);
            self::assertSame('image/webp', (string) ($item['mime_type'] ?? ''));
            self::assertGreaterThan(0, (int) ($item['width'] ?? 0));
            self::assertGreaterThan(0, (int) ($item['height'] ?? 0));
            self::assertGreaterThan(0, (int) ($item['byte_size'] ?? 0));
            self::assertStringEndsWith('.webp', (string) ($item['filename'] ?? ''));
            self::assertNotSame('camera-original.png', (string) ($item['filename'] ?? ''));
            self::assertSame('IMG_4644.jpeg', $item['original_filename'] ?? null);
            self::assertSame('IMG_4644.jpeg', (string) get_post_meta($attachmentId, '_nhk_original_filename', true));
            self::assertNotNull($ingestor->read($attachmentId));
            self::assertNotEmpty($assets->listByMediaId($mediaId));
            $sourceRelative = (string) get_post_meta($attachmentId, '_nhk_source_original_file', true);
            self::assertStringStartsWith('private/', $sourceRelative);
        } finally {
            if ($attachmentId > 0 && function_exists('wp_delete_attachment')) wp_delete_attachment($attachmentId, true);
            if ($mediaId !== '') {
                $internalId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($mediaId)));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_usages WHERE media_id=%d", $internalId));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_assets WHERE media_id=%d", $internalId));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE id=%d", $internalId));
            }
            if ($sourceRelative !== '') try { PrivateMediaSourceStorage::fromWordPress()->delete($sourceRelative); } catch (\Throwable) { }
            if (function_exists('delete_option')) delete_option('nhk_media_upload_batch_' . hash('sha256', $idempotencyKey));
        }
    }
}
