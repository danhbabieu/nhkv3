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

    public function test_same_endpoint_role_and_placement_reconciles_to_new_media_without_duplicate_usage(): void
    {
        $old = new Media(UuidCodec::newV7(), 'old-media', 'Ảnh cũ', 'ready');
        $new = new Media(UuidCodec::newV7(), 'new-media', 'Ảnh mới', 'ready');
        $mediaRepository = new class([$old, $new]) implements MediaRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Media { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(Media $media): Media { return $this->items[] = $media; }
            public function update(Media $media, int $revision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
        $assetRepository = new class implements MediaAssetRepository {
            public function findByAssetId(string $id): ?MediaAsset { return null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $id): array { return []; }
            public function findByChecksum(string $checksum): array { return []; }
        };
        $usageRepository = new class implements MediaUsageRepository, MediaUsageUpdater {
            public array $items = [];
            public function create(MediaUsage $usage): MediaUsage { return $this->items[] = $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->mediaId === $id && ($role === null || $item->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->endpointType === $type && $item->endpointKey === $key && ($role === null || $item->role === $role))); }
            public function update(MediaUsage $usage): MediaUsage { foreach ($this->items as $index => $item) if ($item->usageId === $usage->usageId) return $this->items[$index] = new MediaUsage($usage->usageId, $usage->mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder, $usage->altText, $usage->caption, $usage->keywordGroups, $usage->title, $usage->revision + 1, $usage->placementKey); throw new \RuntimeException('usage missing'); }
        };
        $service = new MediaService($mediaRepository, $assetRepository, $usageRepository);
        $existing = $service->addUsage($old->canonicalId, 'classification', 'classification-1', 'featured_primary');
        $updated = $service->addUsage($new->canonicalId, 'classification', 'classification-1', 'featured_primary', 0, 'Ảnh mới');

        self::assertSame($existing->usageId, $updated->usageId);
        self::assertSame($new->canonicalId, $updated->mediaId);
        self::assertSame('Ảnh mới', $updated->altText);
        self::assertCount(1, $usageRepository->items);
    }

    public function test_concurrent_create_conflict_resolves_existing_usage_and_preserves_identity(): void
    {
        [$mediaRepository, $assetRepository, $usageRepository, $mediaId] = $this->stores();
        $usageRepository->race = true;
        $service = new MediaService($mediaRepository, $assetRepository, $usageRepository);
        $result = $service->addUsage($mediaId, 'classification', 'classification-race', 'featured_primary', 0, 'Concurrent');

        self::assertSame('Concurrent', $result->altText);
        self::assertSame('classification-race', $result->endpointKey);
        self::assertCount(1, $usageRepository->items);
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
            public bool $race = false;
            public function create(MediaUsage $usage): MediaUsage
            {
                if ($this->race) {
                    $this->race = false;
                    $this->items[] = new MediaUsage(UuidCodec::newV7(), $usage->mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder);
                    throw new MediaException('Media usage identity already exists.');
                }
                return $this->items[] = $usage;
            }
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
