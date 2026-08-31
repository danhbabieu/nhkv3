<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaService;
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Infrastructure\Graph\{MediaEndpointResolver, VideoEndpointResolver};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class P6PersistenceTest extends TestCase
{
    public function test_media_service_keeps_identity_separate_from_assets_and_usage(): void
    {
        $media = new class implements MediaRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Media { return $this->items[$id] ?? null; }
            public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(Media $item): Media { return $this->items[$item->canonicalId] = $item; }
            public function update(Media $item, int $revision): Media { if (($this->items[$item->canonicalId]->revision ?? 0) !== $revision) throw new \RuntimeException('stale'); $next = new Media($item->canonicalId, $item->stableKey, $item->canonicalName, $item->readiness, $item->provenance, $item->active, $revision + 1); return $this->items[$item->canonicalId] = $next; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $assets = new class implements MediaAssetRepository {
            public array $items = [];
            public function findByAssetId(string $id): ?MediaAsset { return $this->items[$id] ?? null; }
            public function create(MediaAsset $item): MediaAsset { return $this->items[$item->assetId] = $item; }
            public function listByMediaId(string $id): array { return array_values(array_filter($this->items, fn (MediaAsset $item): bool => $item->mediaId === $id)); }
            public function findByChecksum(string $checksum): array { return array_values(array_filter($this->items, fn (MediaAsset $item): bool => $item->checksum === $checksum)); }
        };
        $usages = new class implements MediaUsageRepository {
            public array $items = [];
            public function create(MediaUsage $item): MediaUsage { return $this->items[$item->usageId] = $item; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, fn (MediaUsage $item): bool => $item->mediaId === $id && ($role === null || $item->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, fn (MediaUsage $item): bool => $item->endpointType === $type && $item->endpointKey === $key && ($role === null || $item->role === $role))); }
        };
        $service = new MediaService($media, $assets, $usages);
        $created = $service->create('odo-front', 'Odo front', 'ready', ['source' => 'migration']);
        $asset = $service->addAsset($created->canonicalId, 'original', 'uploads/odo-front.jpg', hash('sha256', 'binary'), 'image/jpeg', 6, 1200, 800);
        $usage = $service->addUsage($created->canonicalId, 'wp_post', '1:42', 'featured');
        self::assertSame($created->canonicalId, $asset->mediaId);
        self::assertSame($created->canonicalId, $usage->mediaId);
        self::assertCount(1, $service->assets($created->canonicalId));
        self::assertCount(1, $service->usages($created->canonicalId));
    }

    public function test_video_service_deduplicates_external_reference_without_merging_identity(): void
    {
        $repo = new class implements VideoRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Video { return $this->items[$id] ?? null; }
            public function findByExternalReference(string $platform, string $id): ?Video { foreach ($this->items as $item) if ($item->platform === $platform && $item->externalVideoId === $id) return $item; return null; }
            public function create(Video $item): Video { return $this->items[$item->canonicalId] = $item; }
            public function update(Video $item, int $revision): Video { return $this->items[$item->canonicalId] = new Video($item->canonicalId, $item->platform, $item->externalVideoId, $item->canonicalUrl, $item->title, $item->metadata, $item->thumbnailMediaId, $item->active, $revision + 1); }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $service = new VideoService($repo);
        $first = $service->ingestUrl('https://youtu.be/dQw4w9WgXcQ', 'Reference');
        $same = $service->ingestUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Reference');
        self::assertSame($first->canonicalId, $same->canonicalId);
        self::assertCount(1, $repo->items);
    }

    public function test_media_and_video_are_real_graph_endpoints_and_retired_records_remain_resolvable(): void
    {
        $id = UuidCodec::newV7();
        $media = new Media($id, 'media-key', 'Media');
        $mediaRepo = new class($media) implements MediaRepository {
            public function __construct(private Media $item) {}
            public function findByCanonicalId(string $id): ?Media { return $id === $this->item->canonicalId ? $this->item : null; }
            public function findByStableKey(string $key): ?Media { return $key === $this->item->stableKey ? $this->item : null; }
            public function create(Media $item): Media { return $item; }
            public function update(Media $item, int $revision): Media { return $item; }
            public function list(bool $includeRetired = false): array { return [$this->item]; }
        };
        $mediaResolver = new MediaEndpointResolver($mediaRepo);
        self::assertTrue($mediaResolver->supports('media'));
        self::assertTrue($mediaResolver->exists(new NodeReference('media', $id)));

        $video = Video::fromUrl('https://youtu.be/dQw4w9WgXcQ');
        $videoRepo = new class($video) implements VideoRepository {
            public function __construct(private Video $item) {}
            public function findByCanonicalId(string $id): ?Video { return $id === $this->item->canonicalId ? $this->item : null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return $platform === $this->item->platform && $id === $this->item->externalVideoId ? $this->item : null; }
            public function create(Video $item): Video { return $item; }
            public function update(Video $item, int $revision): Video { return $item; }
            public function list(bool $includeRetired = false): array { return [$this->item]; }
        };
        $videoResolver = new VideoEndpointResolver($videoRepo);
        self::assertTrue($videoResolver->supports('video'));
        self::assertTrue($videoResolver->exists(new NodeReference('video', $video->canonicalId)));
    }
}
