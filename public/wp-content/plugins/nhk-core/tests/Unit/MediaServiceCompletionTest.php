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
            public function findByAssetId(string $id): ?MediaAsset { return $this->items[$id] ?? null; }
            public function create(MediaAsset $asset): MediaAsset { return $this->items[$asset->assetId] = $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $this->items[$asset->assetId] = $asset; }
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
