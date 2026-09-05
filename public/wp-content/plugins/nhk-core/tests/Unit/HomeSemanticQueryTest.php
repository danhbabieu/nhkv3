<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Home\HomeSemanticQuery;
use NHK\Core\Application\Media\PublicMediaGalleryQuery;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Media\{Media, MediaAsset};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class HomeSemanticQueryTest extends TestCase
{
    public function test_home_hides_invalid_public_video_references(): void
    {
        $invalid = new Video(UuidCodec::newV7(), 'vimeo', 'bad-reference', 'https://vimeo.com/bad-reference', 'Invalid');
        $videos = $this->videos([$invalid]);
        $modules = (new HomeSemanticQuery(new InMemoryAuthorityRepository(), $this->media([]), $videos, new EntityTypeRegistry()))
            ->extend(['entities' => [], 'media' => [], 'videos' => []]);
        self::assertSame([], $modules['videos']);
    }

    public function test_home_media_and_video_modules_are_visual_first_without_media_detail_links(): void
    {
        $media = new Media($mediaId = UuidCodec::newV7(), 'front', 'Ảnh mặt trước', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'front.jpg', hash('sha256', 'x'), 'image/jpeg', 1, 1200, 800, 'PUBLIC', ['canonical_filename' => 'front.jpg']);
        $video = new Video(UuidCodec::newV7(), 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Video kỹ thuật', ['source_snapshot' => ['availability' => 'available', 'thumbnail_urls' => ['https://img.example.test/video.jpg']]]);
        $mediaRepo = $this->media([$media]);
        $gallery = new PublicMediaGalleryQuery($mediaRepo, $this->assets([$asset]));

        $modules = (new HomeSemanticQuery(new InMemoryAuthorityRepository(), $mediaRepo, $this->videos([$video]), new EntityTypeRegistry(), null, null, null, $gallery))
            ->extend(['entities' => [], 'media' => [], 'videos' => []]);

        self::assertStringContainsString('/anh/front.jpg', (string) ($modules['media'][0]['image_url'] ?? ''));
        self::assertArrayNotHasKey('url', $modules['media'][0]);
        self::assertSame('https://img.example.test/video.jpg', $modules['videos'][0]['thumbnail_url'] ?? null);
    }

    private function media(array $items): MediaRepository
    {
        return new class($items) implements MediaRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Media { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }

    private function assets(array $items): MediaAssetRepository
    {
        return new class($items) implements MediaAssetRepository {
            public function __construct(private array $items) {}
            public function findByAssetId(string $id): ?MediaAsset { foreach ($this->items as $item) if ($item->assetId === $id) return $item; return null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $mediaId): array { return array_values(array_filter($this->items, static fn(MediaAsset $item): bool => $item->mediaId === $mediaId)); }
            public function findByChecksum(string $checksum): array { return []; }
        };
    }

    private function videos(array $items): VideoRepository
    {
        return new class($items) implements VideoRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Video { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }
}
