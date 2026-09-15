<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaService;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository, MediaUsageUpdater};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaException, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaServiceUsageIdentityTest extends TestCase
{
    public function test_same_media_supporting_placements_are_distinct_usage_identities(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'shared-photo', 'Ảnh dùng chung', 'ready');
        $assets = [new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'uploads/shared.jpg', hash('sha256', 'shared'), 'image/jpeg', 10, 1200, 800, 'PUBLIC')];
        $mediaRepository = new class($media) implements MediaRepository {
            public function __construct(private Media $media) {}
            public function findByCanonicalId(string $id): ?Media { return $id === $this->media->canonicalId ? $this->media : null; }
            public function findByStableKey(string $key): ?Media { return $key === $this->media->stableKey ? $this->media : null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $revision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return [$this->media]; }
        };
        $assetRepository = new class($assets) implements MediaAssetRepository {
            public function __construct(private array $assets) {}
            public function findByAssetId(string $id): ?MediaAsset { return $this->assets[0]->assetId === $id ? $this->assets[0] : null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $id): array { return array_values(array_filter($this->assets, static fn (MediaAsset $asset): bool => $asset->mediaId === $id)); }
            public function findByChecksum(string $checksum): array { return []; }
        };
        $usageRepository = new class implements MediaUsageRepository {
            /** @var list<MediaUsage> */
            public array $items = [];
            public function create(MediaUsage $usage): MediaUsage { return $this->items[] = $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->mediaId === $id && ($role === null || $usage->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $type && $usage->endpointKey === $key && ($role === null || $usage->role === $role))); }
        };
        $service = new MediaService($mediaRepository, $assetRepository, $usageRepository);

        $first = $service->addUsage($mediaId, 'wp_post', '1:500', 'inline_supporting', 0, '', '', [], '', 'detail-front');
        $second = $service->addUsage($mediaId, 'wp_post', '1:500', 'inline_supporting', 1, '', '', [], '', 'detail-back');

        self::assertNotSame($first->usageId, $second->usageId);
        self::assertCount(2, $usageRepository->items);
        self::assertSame(['detail-front', 'detail-back'], array_column($usageRepository->items, 'placementKey'));
    }

    public function test_add_usage_refreshes_once_after_a_stale_update_and_preserves_identity(): void
    {
        [$mediaRepository, $assetRepository, $usageRepository, $mediaId] = $this->stores();
        $service = new MediaService($mediaRepository, $assetRepository, $usageRepository);
        $existing = $service->addUsage($mediaId, 'wp_post', '1:501', 'inline_supporting', 0, 'Cũ', '', [], '', 'detail-front');
        $usageRepository->conflicts = 1;

        $updated = $service->addUsage($mediaId, 'wp_post', '1:501', 'inline_supporting', 0, 'Mới', '', [], '', 'detail-front');

        self::assertSame($existing->usageId, $updated->usageId);
        self::assertSame('Mới', $updated->altText);
        self::assertSame(3, $updated->revision);
        self::assertSame(2, $usageRepository->updates);
    }

    /** @return array{MediaRepository,MediaAssetRepository,object,string} */
    private function stores(): array
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'shared-photo', 'Ảnh dùng chung', 'ready');
        $assets = [new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'uploads/shared.jpg', hash('sha256', 'shared'), 'image/jpeg', 10, 1200, 800, 'PUBLIC')];
        $mediaRepository = new class($media) implements MediaRepository {
            public function __construct(private Media $media) {}
            public function findByCanonicalId(string $id): ?Media { return $id === $this->media->canonicalId ? $this->media : null; }
            public function findByStableKey(string $key): ?Media { return $key === $this->media->stableKey ? $this->media : null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $revision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return [$this->media]; }
        };
        $assetRepository = new class($assets) implements MediaAssetRepository {
            public function __construct(private array $assets) {}
            public function findByAssetId(string $id): ?MediaAsset { return $this->assets[0]->assetId === $id ? $this->assets[0] : null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $id): array { return array_values(array_filter($this->assets, static fn (MediaAsset $asset): bool => $asset->mediaId === $id)); }
            public function findByChecksum(string $checksum): array { return []; }
        };
        $usageRepository = new class implements MediaUsageRepository, MediaUsageUpdater {
            /** @var list<MediaUsage> */
            public array $items = [];
            public int $conflicts = 0;
            public int $updates = 0;
            public function create(MediaUsage $usage): MediaUsage { return $this->items[] = $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->mediaId === $id && ($role === null || $usage->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $type && $usage->endpointKey === $key && ($role === null || $usage->role === $role))); }
            public function update(MediaUsage $usage): MediaUsage
            {
                ++$this->updates;
                $current = $this->items[0] ?? null;
                if ($this->conflicts > 0) {
                    --$this->conflicts;
                    if ($current instanceof MediaUsage) $this->items[0] = new MediaUsage($current->usageId, $current->mediaId, $current->endpointType, $current->endpointKey, $current->role, $current->sortOrder, $current->altText . ' refreshed', $current->caption, $current->keywordGroups, $current->title, $current->revision + 1, $current->placementKey);
                    throw new MediaException('Media usage update conflict.');
                }
                return $this->items[0] = new MediaUsage($usage->usageId, $usage->mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder, $usage->altText, $usage->caption, $usage->keywordGroups, $usage->title, $usage->revision + 1, $usage->placementKey);
            }
        };
        return [$mediaRepository, $assetRepository, $usageRepository, $mediaId];
    }
}
