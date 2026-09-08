<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\PublicMediaGalleryQuery;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaLibraryFrontendContractTest extends TestCase
{
    public function test_gallery_projects_canonical_image_link_and_caption_summary(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'example', 'Ảnh tư liệu', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'uploads/example.webp', hash('sha256', 'image'), 'image/webp', 5, 1200, 800, 'PUBLIC', ['canonical_filename' => 'example.webp']);
        $usage = new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:42', 'featured', 0, 'Ảnh mặt trước', 'Tư liệu ảnh mặt trước của hiện vật.');

        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$asset]), null, $this->usageRepository([$usage])))->archive()['items'][0];

        self::assertSame('/anh/example.webp', $item['image_url']);
        self::assertTrue($item['has_real_image']);
        self::assertSame('Tư liệu ảnh mặt trước của hiện vật.', $item['summary']);
        self::assertStringStartsWith('/anh/', parse_url((string) $item['image_url'], PHP_URL_PATH) ?: '');
        self::assertStringNotContainsString('/wp-content/uploads/', (string) $item['image_url']);
    }

    public function test_gallery_uses_short_fallback_summary_without_inventing_semantics(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'example', 'Ảnh tư liệu', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'example.webp', hash('sha256', 'image'), 'image/webp', 5, 1200, 800, 'PUBLIC', ['canonical_filename' => 'example.webp']);

        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$asset])))->archive()['items'][0];

        self::assertSame('Ảnh tư liệu trong kho hình ảnh NHK.', $item['summary']);
    }

    public function test_gallery_marks_only_media_without_a_public_image_asset_as_placeholder_candidate(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'example', 'Ảnh tư liệu', 'ready');
        $private = new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'example.webp', hash('sha256', 'image'), 'image/webp', 5, 1200, 800, 'PRIVATE', ['canonical_filename' => 'example.webp']);

        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$private])))->archive()['items'][0];

        self::assertNull($item['image_url']);
        self::assertFalse($item['has_real_image']);
    }

    public function test_media_template_links_real_image_and_title_and_renders_summary(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/media.php');

        self::assertStringContainsString('class="library-image-link"', $template);
        self::assertStringContainsString('class="library-title-link"', $template);
        self::assertStringContainsString("['summary']", $template);
        self::assertStringContainsString('Xem ảnh', $template);
        self::assertStringContainsString("['has_real_image']", $template);
    }

    public function test_media_template_uses_a_compact_responsive_grid_and_preserves_image_ratio(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/media-video.css');

        self::assertStringContainsString('.media-library-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))', $css);
        self::assertStringContainsString('@media(max-width:64rem){.media-library-grid{grid-template-columns:repeat(2,minmax(0,1fr))', $css);
        self::assertStringContainsString('@media(max-width:36rem){.media-library-grid{grid-template-columns:1fr}', $css);
        self::assertStringContainsString('object-fit:contain', $css);
    }

    private function mediaRepository(array $items): MediaRepository
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

    private function assetRepository(array $items): MediaAssetRepository
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

    private function usageRepository(array $items): MediaUsageRepository
    {
        return new class($items) implements MediaUsageRepository {
            public function __construct(private array $items) {}
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $mediaId, ?string $role = null): array { return array_values(array_filter($this->items, static fn(MediaUsage $item): bool => $item->mediaId === $mediaId && ($role === null || $item->role === $role))); }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return []; }
        };
    }
}
