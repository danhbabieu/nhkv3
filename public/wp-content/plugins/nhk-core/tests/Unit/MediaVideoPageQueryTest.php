<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaVideoPageQuery;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaVideoPageQueryTest extends TestCase
{
    public function test_archives_are_active_only_and_paginated(): void
    {
        $mediaId = UuidCodec::newV7();
        $retiredMedia = new Media(UuidCodec::newV7(), 'retired', 'Retired', 'ready', [], false);
        $activeMedia = new Media($mediaId, 'active', 'Active', 'ready');
        $video = Video::fromUrl('https://youtu.be/dQw4w9WgXcQ', 'Reference');
        $retiredVideo = new Video(UuidCodec::newV7(), 'youtube', '9bZkp7q19f0', 'https://www.youtube.com/watch?v=9bZkp7q19f0', 'Retired', [], null, false);
        $query = $this->query([$retiredMedia, $activeMedia], [$video, $retiredVideo]);

        self::assertSame(['active'], array_column($query->mediaArchive(1, 10)['items'], 'stable_key'));
        self::assertSame([$video->canonicalId], array_column($query->videoArchive(1, 10)['items'], 'id'));
    }

    public function test_media_detail_contains_assets_and_usages_but_video_detail_keeps_external_reference(): void
    {
        $mediaId = UuidCodec::newV7();
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'uploads/odo/front.jpg', hash('sha256', 'image'), 'image/jpeg', 5, 1200, 800);
        $privateAsset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'uploads/odo/private.jpg', hash('sha256', 'private-image'), 'image/jpeg', 7, 1200, 800, 'PRIVATE', ['status' => 'private']);
        $usage = new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:42', 'featured');
        $video = Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Reference');
        $query = $this->query([new Media($mediaId, 'odo-front', 'Odo front', 'ready')], [$video], [$asset, $privateAsset], [$usage]);

        $media = $query->mediaDetail($mediaId);
        self::assertSame('uploads/odo/front.jpg', $media['assets'][0]['storage_key']);
        self::assertCount(1, $media['assets']);
        self::assertSame('wp_post', $media['usages'][0]['endpoint_type']);
        self::assertSame($video->canonicalUrl, $query->videoDetail($video->canonicalId)['url']);
        self::assertSame('dQw4w9WgXcQ', $query->videoDetail($video->canonicalId)['external_id']);
    }

    /** @param list<Media> $media @param list<Video> $videos @param list<MediaAsset> $assets @param list<MediaUsage> $usages */
    private function query(array $media, array $videos, array $assets = [], array $usages = []): MediaVideoPageQuery
    {
        $mediaRepository = new class ($media) implements MediaRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Media { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?Media { foreach ($this->items as $item) if ($item->stableKey === $stableKey) return $item; return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return $includeRetired ? $this->items : array_values(array_filter($this->items, static fn (Media $item): bool => $item->active)); }
        };
        $videoRepository = new class ($videos) implements VideoRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Video { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { foreach ($this->items as $item) if ($item->platform === $platform && $item->externalVideoId === $externalId) return $item; return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return $includeRetired ? $this->items : array_values(array_filter($this->items, static fn (Video $item): bool => $item->active)); }
        };
        $assetRepository = new class ($assets) implements MediaAssetRepository {
            public function __construct(private array $items) {}
            public function findByAssetId(string $id): ?MediaAsset { foreach ($this->items as $item) if ($item->assetId === $id) return $item; return null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $mediaId): array { return array_values(array_filter($this->items, static fn (MediaAsset $item): bool => $item->mediaId === $mediaId)); }
            public function findByChecksum(string $checksum): array { return array_values(array_filter($this->items, static fn (MediaAsset $item): bool => $item->checksum === $checksum)); }
        };
        $usageRepository = new class ($usages) implements MediaUsageRepository {
            public function __construct(private array $items) {}
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $mediaId, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->mediaId === $mediaId && ($role === null || $item->role === $role))); }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return []; }
        };
        return new MediaVideoPageQuery($mediaRepository, $assetRepository, $usageRepository, $videoRepository);
    }
}
