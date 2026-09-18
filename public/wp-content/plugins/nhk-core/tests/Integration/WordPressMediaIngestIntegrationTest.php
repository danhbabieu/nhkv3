<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Media\{ImageIngestEntrypoint, MediaBatchUploadService, MediaService, PublicImageSizingPolicy};
use NHK\Core\Domain\Media\{Media, MediaUsage};
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
        $wordpressPath = getenv('NHK_WP_TEST_PATH');
        $bootstrap = $wordpressPath === false
            ? ''
            : rtrim((string) $wordpressPath, '/') . '/wp-load.php';
        if ($bootstrap === '' || !is_file($bootstrap)) {
            self::markTestSkipped('INFRASTRUCTURE_UNAVAILABLE: WordPress integration bootstrap is absent; set NHK_WP_TEST_PATH=public.');
        }
        require_once $bootstrap;
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

    public function test_edited_mapped_attachment_re_adopts_in_place_and_preserves_usage_ids_and_visibility(): void
    {
        global $wpdb;
        $fixture = ABSPATH . 'wp-admin/images/post-formats-vs.png';
        $editedFixture = ABSPATH . 'wp-admin/images/post-formats32-vs.png';
        $attachmentId = 0;
        $mediaId = '';
        $sourceKeys = [];
        $publicPaths = [];
        $uploadedPath = '';
        try {
            $media = new WpdbMediaRepository($wpdb);
            $assets = new WpdbMediaAssetRepository($wpdb);
            $usages = new WpdbMediaUsageRepository($wpdb);
            $service = new MediaService($media, $assets, $usages);
            $bridge = new WordPressMediaAttachmentBridge($wpdb, $service, $media, $assets);
            $ingestor = new WordPressMediaAttachmentIngestor($bridge);

            WordPressMediaAttachmentWriteGuard::enter();
            try {
                $upload = wp_upload_bits('nhk-edited-readback.png', null, (string) file_get_contents($fixture));
                self::assertEmpty($upload['error'] ?? null);
                $uploadedPath = (string) $upload['file'];
                $attachmentId = (int) wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Edited readback', 'post_status' => 'inherit'], $uploadedPath, 0, true);
                update_post_meta($attachmentId, '_wp_attached_file', _wp_relative_upload_path($uploadedPath));
                wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $uploadedPath));
            } finally {
                WordPressMediaAttachmentWriteGuard::leave();
            }

            $mediaId = (string) $bridge->adoptAttachment($attachmentId, ['canonical_name' => 'Edited readback', 'seo_slug' => 'edited-readback']);
            self::assertNotSame('', $mediaId);
            $representativeUsage = $usages->create(new MediaUsage(UuidCodec::newV7(), $mediaId, 'classification', 'clock-type.cuckoo-clock', 'representative', 0, 'Representative alt', 'Representative caption'));
            $articleUsage = $usages->create(new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:572', 'featured', 0, 'Article alt', 'Article caption'));
            $dictionaryUsage = $usages->create(new MediaUsage(UuidCodec::newV7(), $mediaId, 'dictionary', 'clock-face', 'illustration', 0, 'Dictionary alt', 'Dictionary caption'));
            $technicalUsage = $usages->create(new MediaUsage(UuidCodec::newV7(), $mediaId, 'media', $mediaId, 'technical_detail', 0, 'Technical alt', 'Technical caption'));
            $beforeUsageIds = $this->usageSnapshot($usages, $mediaId);
            $beforeAssets = $assets->listByMediaId($mediaId);
            $beforeMediaIds = array_map(static fn ($asset): string => $asset->assetId, $beforeAssets);
            $beforeSource = array_values(array_filter($beforeAssets, static fn ($asset): bool => $asset->kind === 'original'))[0];
            $beforeDerivative = array_values(array_filter($beforeAssets, static fn ($asset): bool => $asset->kind === 'derivative'))[0];

            self::assertTrue(copy($editedFixture, $uploadedPath));
            $reAdopted = $bridge->adoptAttachment($attachmentId, ['selection_source' => 'IMAGE_EDITOR']);
            self::assertSame($mediaId, $reAdopted);
            $secondAdoption = $bridge->adoptAttachment($attachmentId, ['selection_source' => 'IMAGE_EDITOR']);
            self::assertSame($mediaId, $secondAdoption);
            $afterUsageIds = $this->usageSnapshot($usages, $mediaId);
            $afterAssets = $assets->listByMediaId($mediaId);
            self::assertSame($beforeMediaIds, array_map(static fn ($asset): string => $asset->assetId, $afterAssets));
            $afterSource = array_values(array_filter($afterAssets, static fn ($asset): bool => $asset->assetId === $beforeSource->assetId))[0];
            $afterDerivative = array_values(array_filter($afterAssets, static fn ($asset): bool => $asset->assetId === $beforeDerivative->assetId))[0];
            self::assertSame(hash_file('sha256', $uploadedPath), $afterSource->checksum);
            self::assertSame('PRIVATE', $afterSource->visibility);
            self::assertSame('PUBLIC', $afterDerivative->visibility);
            $mapping = $wpdb->get_row($wpdb->prepare(
                "SELECT asset_uuid, storage_key FROM {$wpdb->prefix}nhk_media_wordpress_attachments WHERE attachment_id=%d",
                $attachmentId,
            ), ARRAY_A);
            self::assertIsArray($mapping);
            self::assertSame($beforeSource->assetId, UuidCodec::fromBinary((string) ($mapping['asset_uuid'] ?? '')));
            self::assertSame($afterSource->storageKey, (string) ($mapping['storage_key'] ?? ''));
            self::assertSame($beforeUsageIds, $afterUsageIds);
            self::assertSame(4, $beforeUsageIds['count']);
            self::assertSame($beforeUsageIds['count'], $afterUsageIds['count']);
            self::assertCount(4, $afterUsageIds['ids']);
            self::assertContains($representativeUsage->usageId, $afterUsageIds['ids']);
            self::assertContains($articleUsage->usageId, $afterUsageIds['ids']);
            self::assertContains($dictionaryUsage->usageId, $afterUsageIds['ids']);
            self::assertContains($technicalUsage->usageId, $afterUsageIds['ids']);
            $roles = array_map(static fn (MediaUsage $usage): string => $usage->role, $usages->listByMediaId($mediaId));
            self::assertContains('representative', $roles);
            self::assertContains('featured', $roles);
            self::assertContains('illustration', $roles);
            self::assertContains('technical_detail', $roles);
            self::assertNotFalse(has_action('add_attachment'));
            self::assertNotFalse(has_action('edit_attachment'));
            self::assertNotFalse(has_action('rest_after_insert_attachment'));
            do_action('add_attachment', $attachmentId);
            self::assertSame($mediaId, $this->mappedMediaId($wpdb, $attachmentId));
            do_action('edit_attachment', $attachmentId);
            self::assertSame($mediaId, $this->mappedMediaId($wpdb, $attachmentId));
            $restPost = get_post($attachmentId);
            self::assertInstanceOf(\WP_Post::class, $restPost);
            do_action('rest_after_insert_attachment', $restPost, new \WP_REST_Request('POST', '/wp/v2/media/' . $attachmentId), false);
            self::assertSame($mediaId, $this->mappedMediaId($wpdb, $attachmentId));
            $storedMedia = $media->findByCanonicalId($mediaId);
            self::assertNotNull($storedMedia);
            self::assertSame($attachmentId, $bridge->attachmentForMedia($storedMedia, $afterSource)['attachment_id'] ?? null);
            self::assertSame('VERIFIED', $ingestor->read($attachmentId)['readback_state']);
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
            if ($uploadedPath !== '' && is_file($uploadedPath)) @unlink($uploadedPath);
        }
    }

    public function test_existing_attachment_with_missing_physical_file_reads_as_unavailable(): void
    {
        global $wpdb;
        $fixture = ABSPATH . 'wp-admin/images/post-formats-vs.png';
        $attachmentId = 0;
        $uploadedPath = '';
        try {
            WordPressMediaAttachmentWriteGuard::enter();
            try {
                $upload = wp_upload_bits('nhk-missing-physical.png', null, (string) file_get_contents($fixture));
                self::assertEmpty($upload['error'] ?? null);
                $uploadedPath = (string) $upload['file'];
                $attachmentId = (int) wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Missing physical', 'post_status' => 'inherit'], $uploadedPath, 0, true);
                update_post_meta($attachmentId, '_wp_attached_file', _wp_relative_upload_path($uploadedPath));
            } finally {
                WordPressMediaAttachmentWriteGuard::leave();
            }
            self::assertTrue(unlink($uploadedPath));
            $read = (new WordPressMediaAttachmentIngestor())->read($attachmentId);
            self::assertIsArray($read);
            self::assertSame($attachmentId, $read['attachment_id']);
            self::assertSame('UNAVAILABLE', $read['readback_state']);
        } finally {
            if ($attachmentId > 0 && function_exists('wp_delete_attachment')) wp_delete_attachment($attachmentId, true);
            if ($uploadedPath !== '' && is_file($uploadedPath)) @unlink($uploadedPath);
        }
    }

    public function test_conflicting_attachment_mapping_reads_as_inconsistent_without_duplicate_media(): void
    {
        global $wpdb;
        $fixture = ABSPATH . 'wp-admin/images/post-formats-vs.png';
        $attachmentId = 0;
        $uploadedPath = '';
        $mediaIds = [];
        try {
            $media = new WpdbMediaRepository($wpdb);
            $assets = new WpdbMediaAssetRepository($wpdb);
            $service = new MediaService($media, $assets, new WpdbMediaUsageRepository($wpdb));
            $bridge = new WordPressMediaAttachmentBridge($wpdb, $service, $media, $assets);
            WordPressMediaAttachmentWriteGuard::enter();
            try {
                $upload = wp_upload_bits('nhk-conflicting-mapping.png', null, (string) file_get_contents($fixture));
                self::assertEmpty($upload['error'] ?? null);
                $uploadedPath = (string) $upload['file'];
                $attachmentId = (int) wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Conflicting mapping', 'post_status' => 'inherit'], $uploadedPath, 0, true);
                update_post_meta($attachmentId, '_wp_attached_file', _wp_relative_upload_path($uploadedPath));
                wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $uploadedPath));
            } finally {
                WordPressMediaAttachmentWriteGuard::leave();
            }
            $first = (string) $bridge->adoptAttachment($attachmentId, ['canonical_name' => 'Conflicting mapping']);
            $mediaIds[] = $first;
            $conflictingMedia = $media->create(new Media(UuidCodec::newV7(), 'wp-attachment-conflict:' . max(1, (int) get_current_blog_id()) . ':' . $attachmentId, 'Conflicting second identity', 'draft'));
            $mediaIds[] = $conflictingMedia->canonicalId;
            $mappingTable = $wpdb->prefix . 'nhk_media_wordpress_attachments';
            self::assertSame(1, (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$mappingTable} SET media_uuid=%s WHERE attachment_id=%d",
                UuidCodec::toBinary($conflictingMedia->canonicalId),
                $attachmentId,
            )));
            $countBefore = count($media->list());
            $read = (new WordPressMediaAttachmentIngestor($bridge))->read($attachmentId);
            self::assertIsArray($read);
            self::assertSame('INCONSISTENT', $read['readback_state']);
            self::assertSame('ATTACHMENT_MAPPING_CONFLICT', $read['error_code']);
            self::assertCount($countBefore, $media->list());
        } finally {
            if ($attachmentId > 0 && function_exists('wp_delete_attachment')) wp_delete_attachment($attachmentId, true);
            foreach ($mediaIds as $mediaId) {
                $internalId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", UuidCodec::toBinary($mediaId)));
                if ($internalId > 0) {
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_usages WHERE media_id=%d", $internalId));
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_assets WHERE media_id=%d", $internalId));
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE id=%d", $internalId));
                }
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_wordpress_attachments WHERE media_uuid=%s", UuidCodec::toBinary($mediaId)));
            }
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
            $derivativeAsset = array_values(array_filter($mediaAssets, static fn ($asset): bool => $asset->kind === 'derivative' && $asset->visibility === 'PUBLIC'))[0] ?? null;
            $sourceAsset = array_values(array_filter($mediaAssets, static fn ($asset): bool => $asset->kind === 'original' && $asset->visibility === 'PRIVATE'))[0] ?? null;
            self::assertNotNull($derivativeAsset);
            self::assertNotNull($sourceAsset);
            $mappingAssetBinary = $wpdb->get_var($wpdb->prepare("SELECT asset_uuid FROM {$wpdb->prefix}nhk_media_wordpress_attachments WHERE attachment_id=%d", $attachmentId));
            self::assertIsString($mappingAssetBinary);
            self::assertSame($derivativeAsset->assetId, UuidCodec::fromBinary($mappingAssetBinary));
            self::assertNotSame($sourceAsset->assetId, UuidCodec::fromBinary($mappingAssetBinary));
            $sourceRelative = (string) get_post_meta($attachmentId, '_nhk_source_original_file', true);
            self::assertNotSame('', $sourceRelative);
            self::assertStringStartsWith('private/', $sourceRelative);
            self::assertNotNull(PrivateMediaSourceStorage::fromWordPress()->path($sourceRelative));
            self::assertSame('source-original.png', (string) get_post_meta($attachmentId, '_nhk_original_filename', true));
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

    /** @dataProvider exifOrientationCases */
    public function test_real_file_ingest_normalizes_exif_orientation_before_attachment_readback(int $orientation, string $topColor, string $bottomColor): void
    {
        global $wpdb;
        $source = $this->createOrientedJpeg($orientation);
        $attachmentId = 0;
        $mediaId = '';
        $sourceRelative = '';
        $publicPath = '';
        try {
            $media = new WpdbMediaRepository($wpdb);
            $assets = new WpdbMediaAssetRepository($wpdb);
            $bridge = new WordPressMediaAttachmentBridge(
                $wpdb,
                new MediaService($media, $assets, new WpdbMediaUsageRepository($wpdb)),
                $media,
                $assets,
            );
            $result = (new WordPressMediaAttachmentIngestor($bridge))->ingest(
                ['error' => UPLOAD_ERR_OK, 'tmp_name' => $source],
                'exif-orientation-' . $orientation . '.jpg',
                'EXIF orientation ' . $orientation,
                1200,
                1200,
                86,
            );
            $attachmentId = (int) $result['attachment_id'];
            $mediaId = (string) ($result['media_id'] ?? '');
            self::assertSame(['width' => 900, 'height' => 1200], ['width' => (int) $result['width'], 'height' => (int) $result['height']]);
            self::assertSame('image/webp', $result['mime']);

            $attachedRelative = (string) get_post_meta($attachmentId, '_wp_attached_file', true);
            $publicPath = rtrim((string) wp_upload_dir()['basedir'], '/\\') . '/' . ltrim($attachedRelative, '/');
            self::assertFileExists($publicPath);
            $publicInfo = getimagesize($publicPath);
            self::assertIsArray($publicInfo);
            self::assertSame(900, (int) $publicInfo[0]);
            self::assertSame(1200, (int) $publicInfo[1]);
            self::assertSame('image/webp', (string) ($publicInfo['mime'] ?? ''));
            $image = imagecreatefromwebp($publicPath);
            self::assertInstanceOf(\GdImage::class, $image);
            $this->assertDominantColor($image, 450, 300, $topColor);
            $this->assertDominantColor($image, 450, 900, $bottomColor);
            $outputExif = @exif_read_data($publicPath);
            self::assertFalse(is_array($outputExif) && isset($outputExif['Orientation']));

            $sourceRelative = (string) get_post_meta($attachmentId, '_nhk_source_original_file', true);
            self::assertStringStartsWith('private/', $sourceRelative);
            $mediaAssets = (new WpdbMediaAssetRepository($wpdb))->listByMediaId($mediaId);
            $publicAssets = array_values(array_filter($mediaAssets, static fn ($asset): bool => $asset->kind === 'derivative' && $asset->visibility === 'PUBLIC'));
            self::assertCount(1, $publicAssets);
            self::assertSame(900, $publicAssets[0]->width);
            self::assertSame(1200, $publicAssets[0]->height);
            self::assertNotNull((new WordPressMediaAttachmentIngestor())->read($attachmentId));
        } finally {
            if ($attachmentId > 0 && function_exists('wp_delete_attachment')) wp_delete_attachment($attachmentId, true);
            if ($mediaId !== '') {
                $internalId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", UuidCodec::toBinary($mediaId)));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_usages WHERE media_id=%d", $internalId));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_assets WHERE media_id=%d", $internalId));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE id=%d", $internalId));
            }
            if ($sourceRelative !== '') try { PrivateMediaSourceStorage::fromWordPress()->delete($sourceRelative); } catch (\Throwable) { }
            if ($publicPath !== '' && is_file($publicPath)) @unlink($publicPath);
            if (is_file($source)) unlink($source);
        }
    }

    /** @return array<string,array{int,string,string}> */
    public static function exifOrientationCases(): array
    {
        return [
            'orientation 6' => [6, 'red', 'blue'],
            'orientation 8' => [8, 'blue', 'red'],
        ];
    }

    /** @return array{count:int,ids:list<string>} */
    private function usageSnapshot(WpdbMediaUsageRepository $usages, string $mediaId): array
    {
        $ids = array_map(static fn (MediaUsage $usage): string => $usage->usageId, $usages->listByMediaId($mediaId));
        sort($ids);
        return ['count' => count($ids), 'ids' => array_values($ids)];
    }

    private function mappedMediaId(object $wpdb, int $attachmentId): ?string
    {
        $binary = $wpdb->get_var($wpdb->prepare(
            "SELECT media_uuid FROM {$wpdb->prefix}nhk_media_wordpress_attachments WHERE attachment_id=%d",
            $attachmentId,
        ));
        return is_string($binary) && strlen($binary) === 16 ? UuidCodec::fromBinary($binary) : null;
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

    private function createOrientedJpeg(int $orientation): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nhk-orientation-integration-');
        self::assertIsString($path);
        $image = imagecreatetruecolor(1536, 1152);
        $red = imagecolorallocate($image, 230, 30, 30);
        $blue = imagecolorallocate($image, 30, 30, 230);
        imagefilledrectangle($image, 0, 0, 767, 1151, $red);
        imagefilledrectangle($image, 768, 0, 1535, 1151, $blue);
        self::assertTrue(imagejpeg($image, $path, 100));

        $jpeg = file_get_contents($path);
        self::assertIsString($jpeg);
        $tiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1)
            . pack('v', 0x0112) . pack('v', 3) . pack('V', 1)
            . pack('v', $orientation) . pack('v', 0) . pack('V', 0);
        $app1 = "Exif\0\0" . $tiff;
        self::assertTrue((bool) file_put_contents(
            $path,
            substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2),
        ));
        return $path;
    }

    private function assertDominantColor(\GdImage $image, int $x, int $y, string $expected): void
    {
        $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        if ($expected === 'red') {
            self::assertGreaterThan($rgb['blue'] + 80, $rgb['red']);
        } else {
            self::assertGreaterThan($rgb['red'] + 80, $rgb['blue']);
        }
    }
}
