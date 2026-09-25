<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoMediaPresentationResolver;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class VideoMediaPresentationResolverTest extends TestCase
{
    public function test_representative_media_wins_over_source_thumbnail(): void
    {
        $video = $this->video(['source_snapshot' => ['thumbnail_selection' => ['url' => 'https://img.youtube.com/source.jpg', 'width' => 640, 'height' => 360]]]);
        $mediaId = UuidCodec::newV7();
        $result = $this->resolver(
            [new Media($mediaId, 'video-cover', 'Video cover', 'ready')],
            [new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'cover.jpg', hash('sha256', 'cover'), 'image/jpeg', 5, 1200, 675, 'PUBLIC', ['canonical_filename' => 'cover.webp'])],
            [new MediaUsage(UuidCodec::newV7(), $mediaId, 'video', $video->canonicalId, 'representative', activeSlot: 'representative', selectionSource: 'USER_EXPLICIT', selectionPolicy: 'PINNED')],
        )->resolve($video);

        self::assertSame('representative', $result['status']);
        self::assertSame($mediaId, $result['media_id']);
        self::assertSame('/anh/cover.webp', $result['thumbnail_url']);
    }

    public function test_source_thumbnail_is_distinguished_from_missing_representative(): void
    {
        $video = $this->video(['source_snapshot' => ['thumbnail_selection' => ['url' => 'https://img.youtube.com/source.jpg', 'width' => 640, 'height' => 360]]]);

        $result = $this->resolver([], [], [])->resolve($video);

        self::assertSame('source', $result['status']);
        self::assertSame('Đang dùng ảnh nguồn', $result['status_label']);
        self::assertSame('https://img.youtube.com/source.jpg', $result['thumbnail_url']);
    }

    public function test_missing_source_and_representative_returns_missing_state(): void
    {
        $result = $this->resolver([], [], [])->resolve($this->video());

        self::assertSame('missing', $result['status']);
        self::assertSame('Thiếu ảnh đại diện', $result['status_label']);
        self::assertNull($result['thumbnail_url']);
    }

    private function video(array $metadata = []): Video
    {
        return Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Video', array_replace_recursive([
            'source_snapshot' => [],
            'public_identity' => ['current_slug' => 'video'],
        ], $metadata));
    }

    private function resolver(array $media, array $assets, array $usages): VideoMediaPresentationResolver
    {
        $mediaRepository = new class($media) implements MediaRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Media { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
        $assetRepository = new class($assets) implements MediaAssetRepository {
            public function __construct(private array $items) {}
            public function findByAssetId(string $id): ?MediaAsset { return null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $mediaId): array { return array_values(array_filter($this->items, static fn (MediaAsset $item): bool => $item->mediaId === $mediaId)); }
            public function findByChecksum(string $checksum): array { return []; }
        };
        $usageRepository = new class($usages) implements MediaUsageRepository {
            public function __construct(private array $items) {}
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $mediaId, ?string $role = null): array { return []; }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->endpointType === $endpointType && $item->endpointKey === $endpointKey && ($role === null || $item->role === $role))); }
        };
        return new VideoMediaPresentationResolver($mediaRepository, $assetRepository, $usageRepository);
    }
}
