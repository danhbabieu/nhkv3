<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaVideoPageQuery;
use NHK\Core\Application\Media\PublicMediaAssetDelivery;
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
        $draftMedia = new Media(UuidCodec::newV7(), 'draft', 'Draft', 'draft');
        $activeMedia = new Media($mediaId, 'active', 'Active', 'ready');
        $video = Video::fromUrl('https://youtu.be/dQw4w9WgXcQ', 'Reference');
        $retiredVideo = new Video(UuidCodec::newV7(), 'youtube', '9bZkp7q19f0', 'https://www.youtube.com/watch?v=9bZkp7q19f0', 'Retired', [], null, false);
        $query = $this->query([$retiredMedia, $draftMedia, $activeMedia], [$video, $retiredVideo]);

        self::assertSame(['Active'], array_column($query->mediaArchive(1, 10)['items'], 'title'));
        self::assertNull($query->mediaDetail($draftMedia->canonicalId));
        self::assertSame(['Reference'], array_column($query->videoArchive(1, 10)['items'], 'title'));
    }

    public function test_media_detail_contains_assets_and_usages_but_video_detail_keeps_external_reference(): void
    {
        $mediaId = UuidCodec::newV7();
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'uploads/odo/front.jpg', hash('sha256', 'image'), 'image/jpeg', 5, 1200, 800, 'PUBLIC');
        $privateAsset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'uploads/odo/private.jpg', hash('sha256', 'private-image'), 'image/jpeg', 7, 1200, 800, 'PRIVATE', ['status' => 'private']);
        $usage = new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:42', 'featured');
        $video = Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Reference', ['source' => 'internal-test']);
        $query = $this->query([new Media($mediaId, 'odo-front', 'Odo front', 'ready', ['source' => 'v2', 'metadata' => ['legacy_id' => '42']])], [$video], [$asset, $privateAsset], [$usage]);

        $media = $query->mediaDetail($mediaId);
        self::assertSame('image/jpeg', $media['assets'][0]['mime_type']);
        self::assertArrayNotHasKey('public_url', $media['assets'][0]);
        self::assertArrayNotHasKey('provenance', $media);
        self::assertArrayNotHasKey('storage_key', $media['assets'][0]);
        self::assertArrayNotHasKey('metadata', $media['assets'][0]);
        self::assertCount(1, $media['assets']);
        self::assertSame('featured', $media['usages'][0]['role']);
        self::assertArrayNotHasKey('endpoint_type', $media['usages'][0]);
        self::assertArrayNotHasKey('endpoint_key', $media['usages'][0]);
        self::assertSame($video->canonicalUrl, $query->videoDetail($video->canonicalId)['url']);
        self::assertSame('dQw4w9WgXcQ', $query->videoDetail($video->canonicalId)['external_id']);
        self::assertArrayNotHasKey('metadata', $query->videoDetail($video->canonicalId));
    }

    public function test_video_detail_and_archive_hide_invalid_persisted_external_references(): void
    {
        $invalid = new Video(UuidCodec::newV7(), 'vimeo', 'bad-reference', 'https://vimeo.com/bad-reference', 'Invalid');
        $query = $this->query([], [$invalid]);

        self::assertNull($query->videoDetail($invalid->canonicalId));
        self::assertSame(0, $query->videoArchive()['total']);
    }

    public function test_media_detail_omits_public_assets_that_binary_delivery_cannot_resolve(): void
    {
        $root = sys_get_temp_dir() . '/nhk-public-media-' . bin2hex(random_bytes(4));
        mkdir($root);
        $contents = 'valid-image';
        file_put_contents($root . '/valid.jpg', $contents);
        $mediaId = UuidCodec::newV7();
        $valid = new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'valid.jpg', hash('sha256', $contents), 'image/jpeg', strlen($contents), null, null, 'PUBLIC');
        $missing = new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'missing.jpg', hash('sha256', 'missing'), 'image/jpeg', 7);
        try {
            $assetRepository = $this->assetRepository([$valid, $missing]);
            $mediaRepository = $this->mediaRepository([new Media($mediaId, 'public-assets', 'Public assets', 'ready')]);
            $query = new MediaVideoPageQuery($mediaRepository, $assetRepository, $this->usageRepository([]), $this->videoRepository([]), null, new PublicMediaAssetDelivery($assetRepository, $mediaRepository, $root));
            self::assertCount(1, $query->mediaDetail($mediaId)['assets']);
            self::assertArrayNotHasKey('id', $query->mediaDetail($mediaId)['assets'][0]);
        } finally {
            unlink($root . '/valid.jpg');
            rmdir($root);
        }
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

    private function mediaRepository(array $items): MediaRepository
    {
        return new class ($items) implements MediaRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Media { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }

    private function assetRepository(array $items): MediaAssetRepository
    {
        return new class ($items) implements MediaAssetRepository {
            public function __construct(private array $items) {}
            public function findByAssetId(string $id): ?MediaAsset { foreach ($this->items as $item) if ($item->assetId === $id) return $item; return null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $mediaId): array { return array_values(array_filter($this->items, static fn (MediaAsset $item): bool => $item->mediaId === $mediaId)); }
            public function findByChecksum(string $checksum): array { return []; }
        };
    }

    private function usageRepository(array $items): MediaUsageRepository
    {
        return new class ($items) implements MediaUsageRepository {
            public function __construct(private array $items) {}
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $mediaId, ?string $role = null): array { return []; }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return []; }
        };
    }

    private function videoRepository(array $items): VideoRepository
    {
        return new class ($items) implements VideoRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }
}
