<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaService;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use PHPUnit\Framework\TestCase;

final class MediaServiceCompletionTest extends TestCase
{
    public function test_wordpress_bridge_delegates_completion_to_canonical_media_service(): void
    {
        $bridge = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php');
        self::assertStringContainsString("$this->mediaService->completeIngest", $bridge);
        self::assertStringContainsString("'canonical_filename'", $bridge);
        self::assertStringContainsString("'visibility' => 'PRIVATE'", $bridge);
    }

    public function test_wordpress_bridge_uses_the_canonical_editorial_state_token(): void
    {
        $bridge = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php');
        self::assertStringContainsString('WpEditorialStateReader', $bridge);
        self::assertStringContainsString('$editorialState->token', $bridge);
        self::assertStringNotContainsString("'state_token' => \$this->stateToken(", $bridge);
    }

    public function test_forced_inline_reconcile_removes_unmapped_legacy_images_except_canonical_target(): void
    {
        $bridge = (new \ReflectionClass(\NHK\Core\Infrastructure\Media\WordPressMediaAttachmentBridge::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass($bridge))->getMethod('removeInlineImagesExcept');
        $method->setAccessible(true);
        $content = '<!-- wp:image {"id":10} --><img src="https://demo.1945.vn/anh/mat-truoc-odo-36-10-thung-kinh-qua-chuong-dep.webp" class="wp-image-10"><!-- /wp:image -->' .
            '<figure><img src="https://cdn.example.test/odo-36-8.webp" class="wp-image-20"></figure>';

        $result = $method->invoke($bridge, $content, 20);

        self::assertStringNotContainsString('mat-truoc-odo-36-10', $result);
        self::assertStringContainsString('wp-image-20', $result);
    }

    public function test_staged_media_completion_promotes_primary_asset_and_preserves_dimensions(): void
    {
        [$media, $assets, $service] = $this->stores();
        $item = $service->ingest('wp-attachment:1:82', 'Đồng hồ 36/10/10 con 10 búa con chữ M búa vuông', 'draft', ['source' => 'wordpress_attachment_adoption'], [[
            'kind' => 'original', 'storage_key' => 'uploads/dong-ho.webp', 'checksum' => hash('sha256', 'webp'), 'mime_type' => 'image/webp', 'byte_size' => 4, 'width' => 996, 'height' => 1280, 'visibility' => 'PRIVATE', 'metadata' => ['canonical_filename' => 'dong-ho-36-10-10-con-10-bua-con-chu-m-bua-vuong.webp', 'sizes' => []],
        ]]);
        $asset = $assets->listByMediaId($item->canonicalId)[0];

        $completed = $service->completeIngest($item->canonicalId, $asset->assetId);
        $primary = $assets->findByAssetId($asset->assetId);

        self::assertSame('ready', $completed->readiness);
        self::assertSame('PUBLIC', $primary?->visibility);
        self::assertSame('image/webp', $primary?->mimeType);
        self::assertSame([996, 1280], [$primary?->width, $primary?->height]);
        self::assertSame('dong-ho-36-10-10-con-10-bua-con-chu-m-bua-vuong.webp', $primary?->metadata['canonical_filename'] ?? null);
        self::assertSame([], $primary?->metadata['sizes'] ?? null);
    }

    public function test_ingest_preserves_attachment_storage_key_when_original_filename_is_a_camera_name(): void
    {
        [$media, $assets, $service] = $this->stores();
        $item = $service->ingest('wp-attachment:1:4644', 'Public clock', 'draft', [], [[
            'kind' => 'derivative',
            'storage_key' => 'uploads/public-clock.webp',
            'original_filename' => 'IMG_4644.jpeg',
            'checksum' => hash('sha256', 'public-clock'),
            'mime_type' => 'image/webp',
            'byte_size' => 12,
            'width' => 1200,
            'height' => 800,
            'visibility' => 'PUBLIC',
            'metadata' => [
                'canonical_filename' => 'public-clock.webp',
                'wordpress_source_attachment_id' => 4644,
            ],
        ]]);

        self::assertSame('uploads/public-clock.webp', $assets->listByMediaId($item->canonicalId)[0]->storageKey);
    }

    public function test_replay_reconciles_partial_asset_metadata_without_rewriting_storage_key_or_identity(): void
    {
        [$media, $assets, $service] = $this->stores();
        $first = $service->ingest('wp-attachment:1:556', 'Vải bô front', 'draft', [], [[
            'kind' => 'original',
            'storage_key' => 'private/556-source.jpeg',
            'checksum' => hash('sha256', 'IMG_4646-scaled.jpeg'),
            'mime_type' => 'image/jpeg',
            'byte_size' => 781688,
            'width' => 2560,
            'height' => 1920,
            'visibility' => 'PRIVATE',
            'metadata' => ['source_original' => true],
        ]]);
        $before = $assets->listByMediaId($first->canonicalId)[0];

        $replayed = $service->ingest('wp-attachment:1:556', 'Vải bô front', 'draft', [], [[
            'kind' => 'original',
            'storage_key' => 'private/556-source.jpeg',
            'original_filename' => 'IMG_4646-scaled.jpeg',
            'checksum' => hash('sha256', 'IMG_4646-scaled.jpeg'),
            'mime_type' => 'image/jpeg',
            'byte_size' => 781688,
            'width' => 2560,
            'height' => 1920,
            'visibility' => 'PRIVATE',
            'metadata' => ['source_original' => true, 'original_filename' => 'IMG_4646-scaled.jpeg', 'wordpress_attachment_id' => 556],
        ]]);
        $after = $assets->listByMediaId($replayed->canonicalId);

        self::assertSame($first->canonicalId, $replayed->canonicalId);
        self::assertCount(1, $after);
        self::assertSame($before->assetId, $after[0]->assetId);
        self::assertSame('private/556-source.jpeg', $after[0]->storageKey);
        self::assertSame(556, $after[0]->metadata['wordpress_attachment_id'] ?? null);
    }

    public function test_normalize_asset_spec_rejects_path_traversal_storage_keys(): void
    {
        [$media, $assets, $service] = $this->stores();

        $this->expectException(\NHK\Core\Domain\Media\MediaException::class);
        $service->ingest('wp-attachment:1:556-invalid', 'Vải bô front', 'draft', [], [[
            'kind' => 'original',
            'storage_key' => '../IMG_4646-scaled.jpeg',
            'checksum' => hash('sha256', 'camera'),
            'mime_type' => 'image/jpeg',
            'byte_size' => 6,
        ]]);
    }

    public function test_normalize_asset_spec_rejects_windows_absolute_storage_keys(): void
    {
        [, , $service] = $this->stores();

        $this->expectException(\NHK\Core\Domain\Media\MediaException::class);
        $service->ingest('wp-attachment:1:556-invalid-drive', 'Invalid drive path', 'draft', [], [[
            'kind' => 'original',
            'storage_key' => 'C:/uploads/image.jpg',
            'checksum' => hash('sha256', 'camera-drive'),
            'mime_type' => 'image/jpeg',
            'byte_size' => 6,
        ]]);
    }

    public function test_completion_does_not_update_an_already_public_asset_without_metadata_changes(): void
    {
        [$media, $assets, $service] = $this->stores();
        $item = $service->ingest('wp-attachment:1:83', 'Đồng hồ đã xử lý', 'draft', ['source' => 'wordpress_attachment_adoption'], [[
            'kind' => 'derivative', 'storage_key' => 'uploads/da-xu-ly.webp', 'checksum' => hash('sha256', 'webp-public'), 'mime_type' => 'image/webp', 'byte_size' => 4, 'width' => 996, 'height' => 1280, 'visibility' => 'PUBLIC', 'metadata' => ['sizes' => []],
        ]]);
        $asset = $assets->listByMediaId($item->canonicalId)[0];

        $completed = $service->completeIngest($item->canonicalId, $asset->assetId);

        self::assertSame('ready', $completed->readiness);
        self::assertSame(0, $assets->updates);
    }

    public function test_reconcile_asset_preserves_the_mapped_asset_identity_and_visibility(): void
    {
        [$media, $assets, $service] = $this->stores();
        $item = $service->ingest('wp-attachment:1:572', 'Edited attachment', 'draft', [], [[
            'kind' => 'original',
            'storage_key' => 'private/572-source.png',
            'checksum' => hash('sha256', 'before'),
            'mime_type' => 'image/png',
            'byte_size' => 6,
            'width' => 900,
            'height' => 1200,
            'visibility' => 'PRIVATE',
            'metadata' => ['source_original' => true, 'wordpress_attachment_id' => 572],
        ]]);
        $asset = $assets->listByMediaId($item->canonicalId)[0];
        $assetId = $asset->assetId;

        self::assertTrue(method_exists($service, 'reconcileAsset'));
        if (!method_exists($service, 'reconcileAsset')) return;

        $reconciled = $service->reconcileAsset($item->canonicalId, $assetId, [
            'storage_key' => 'private/572-edited.png',
            'checksum' => hash('sha256', 'after'),
            'byte_size' => 7,
            'width' => 1200,
            'height' => 900,
            'visibility' => 'PRIVATE',
            'metadata' => ['source_original' => true, 'wordpress_attachment_id' => 572],
        ]);

        self::assertSame($assetId, $reconciled->assetId);
        self::assertSame($item->canonicalId, $reconciled->mediaId);
        self::assertSame('PRIVATE', $reconciled->visibility);
        self::assertSame('private/572-edited.png', $reconciled->storageKey);
    }

    /** @return array{0:object,1:object,2:MediaService} */
    private function stores(): array
    {
        $media = new class implements MediaRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Media { return $this->items[$id] ?? null; }
            public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(Media $item): Media { return $this->items[$item->canonicalId] = $item; }
            public function update(Media $item, int $revision): Media { return $this->items[$item->canonicalId] = $item; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $assets = new class implements MediaAssetRepository {
            public array $items = [];
            public int $updates = 0;
            public function findByAssetId(string $id): ?MediaAsset { return $this->items[$id] ?? null; }
            public function create(MediaAsset $asset): MediaAsset { return $this->items[$asset->assetId] = $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { $this->updates++; return $this->items[$asset->assetId] = $asset; }
            public function listByMediaId(string $id): array { return array_values(array_filter($this->items, static fn (MediaAsset $asset): bool => $asset->mediaId === $id)); }
            public function findByChecksum(string $checksum): array { return array_values(array_filter($this->items, static fn (MediaAsset $asset): bool => $asset->checksum === $checksum)); }
        };
        $usages = new class implements MediaUsageRepository {
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return []; }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return []; }
        };
        return [$media, $assets, new MediaService($media, $assets, $usages)];
    }
}
