<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{PublicMediaArticleLinkResolver, PublicMediaGalleryQuery};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaLibraryFrontendContractTest extends TestCase
{
    public function test_archive_card_projection_does_not_run_binary_delivery_integrity_for_each_card(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'stale-card', 'Ảnh chưa xác thực lại', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'stale-card.webp', hash('sha256', 'stale'), 'image/webp', 5, 1200, 800, 'PUBLIC', ['canonical_filename' => 'stale-card.webp', 'attachment_readback_status' => 'verified']);
        $item = (new PublicMediaGalleryQuery(
            $this->mediaRepository([$media]),
            $this->assetRepository([$asset]),
            $this->usageRepository([]),
            new PublicMediaArticleLinkResolver(static fn (int $postId): object => (object) ['ID' => $postId], static fn (object $post): string => '/bai-viet/khong-mo/'),
        ))->archive()['items'][0] ?? [];

        self::assertSame('/anh/stale-card.webp', parse_url((string) ($item['image_url'] ?? ''), PHP_URL_PATH));
    }

    public function test_archive_card_drops_a_known_unready_asset_without_binary_validation(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'missing-card', 'Ảnh thiếu file', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'missing-card.webp', hash('sha256', 'missing'), 'image/webp', 5, 1200, 800, 'PUBLIC', [
            'canonical_filename' => 'missing-card.webp',
            'attachment_readback_status' => 'missing',
        ]);
        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$asset])))->archive()['items'][0] ?? [];

        self::assertNull($item['image_url'] ?? null);
        self::assertFalse($item['has_real_image'] ?? true);
    }

    public function test_archive_card_without_a_cheap_readiness_signal_fails_closed(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'unknown-card', 'Ảnh chưa có trạng thái delivery', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'unknown-card.webp', hash('sha256', 'unknown'), 'image/webp', 7, 1200, 800, 'PUBLIC', ['canonical_filename' => 'unknown-card.webp']);
        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$asset])))->archive()['items'][0] ?? [];

        self::assertNull($item['image_url'] ?? null);
        self::assertFalse($item['has_real_image'] ?? true);
    }

    public function test_readiness_warning_uses_the_media_asset_identity_field(): void
    {
        $projection = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Media/PublicMediaGalleryQuery.php');

        self::assertStringContainsString('$asset->assetId', $projection);
        self::assertStringNotContainsString('$asset->canonicalId', $projection);
    }

    public function test_archive_projection_keeps_binary_delivery_strict_and_card_projection_has_no_hashing(): void
    {
        $projection = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Media/PublicMediaGalleryQuery.php');
        $delivery = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Media/PublicMediaAssetDelivery.php');

        self::assertStringNotContainsString('hash_file(', $projection);
        self::assertStringContainsString('hash_file(', $delivery);
        self::assertStringContainsString('attachment_readback_status', $projection);
    }

    public function test_gallery_projects_canonical_image_link_and_caption_summary(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'example', 'Ảnh tư liệu', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'uploads/example.webp', hash('sha256', 'image'), 'image/webp', 5, 1200, 800, 'PUBLIC', ['canonical_filename' => 'example.webp', 'attachment_readback_status' => 'verified']);
        $usage = new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:42', 'featured', 0, 'Ảnh mặt trước', 'Tư liệu ảnh mặt trước của hiện vật.');

        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$asset]), $this->usageRepository([$usage]), new PublicMediaArticleLinkResolver(
            static fn (int $postId): object => (object) ['ID' => $postId, 'post_status' => 'publish'],
            static fn (object $post): string => '/bai-viet/anh-mat-truoc/',
        )))->archive()['items'][0];

        self::assertSame('/anh/example.webp', $item['image_url']);
        self::assertTrue($item['has_real_image']);
        self::assertSame('Tư liệu ảnh mặt trước của hiện vật.', $item['summary']);
        self::assertSame('Ảnh tư liệu', $item['title']);
        self::assertSame('/bai-viet/anh-mat-truoc/', $item['article_url']);
        self::assertStringStartsWith('/anh/', parse_url((string) $item['image_url'], PHP_URL_PATH) ?: '');
        self::assertStringNotContainsString('/wp-content/uploads/', (string) $item['image_url']);
    }

    public function test_gallery_does_not_link_to_an_unpublished_or_non_article_endpoint(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'example', 'Ảnh tư liệu', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'example.webp', hash('sha256', 'image'), 'image/webp', 5, 1200, 800, 'PUBLIC', ['canonical_filename' => 'example.webp', 'attachment_readback_status' => 'verified']);
        $usages = [
            new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:42', 'featured'),
            new MediaUsage(UuidCodec::newV7(), $mediaId, 'brand', 'brand-1', 'gallery'),
        ];
        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$asset]), $this->usageRepository($usages), new PublicMediaArticleLinkResolver(
            static fn (int $postId): object => (object) ['ID' => $postId, 'post_status' => 'draft'],
            static fn (object $post): string => '/bai-viet/khong-duoc-mo/',
        )))->archive()['items'][0];

        self::assertNull($item['article_url']);
    }

    public function test_gallery_does_not_choose_one_article_when_two_published_targets_are_equally_valid(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'ambiguous', 'Ảnh dùng chung', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'uploads/ambiguous.webp', hash('sha256', 'image'), 'image/webp', 5, 1200, 800, 'PUBLIC', ['canonical_filename' => 'ambiguous.webp', 'attachment_readback_status' => 'verified']);
        $usages = [
            new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:42', 'featured', 0),
            new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:43', 'featured', 1),
        ];
        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$asset]), $this->usageRepository($usages), new PublicMediaArticleLinkResolver(
            static fn (int $postId): object => (object) ['ID' => $postId, 'post_status' => 'publish', 'post_type' => 'post'],
            static fn (object $post): string => '/bai-viet/' . $post->ID . '/',
        )))->archive()['items'][0];

        self::assertNull($item['article_url']);
        self::assertSame(['/bai-viet/42/', '/bai-viet/43/'], $item['article_urls']);
    }

    public function test_gallery_preserves_all_valid_article_contexts_without_retired_usage(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'shared-context', 'Ảnh dùng chung', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'shared-context.webp', hash('sha256', 'shared-context'), 'image/webp', 5, 1200, 800, 'PUBLIC', ['canonical_filename' => 'shared-context.webp', 'attachment_readback_status' => 'verified']);
        $usages = [
            new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:42', 'featured', 0),
            new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:43', 'gallery', 1),
            new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:44', 'gallery', 2, '', '', [], '', 1, '', 'SYSTEM_AUTO', 'AUTO', 'retired'),
        ];
        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$asset]), $this->usageRepository($usages), new PublicMediaArticleLinkResolver(
            static fn (int $postId): object => (object) ['ID' => $postId, 'post_status' => 'publish', 'post_type' => 'post'],
            static fn (object $post): string => '/bai-viet/' . $post->ID . '/',
        )))->forMedia($mediaId);

        self::assertSame(['/bai-viet/42/', '/bai-viet/43/'], $item['article_urls']);
    }

    public function test_gallery_uses_short_fallback_summary_without_inventing_semantics(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'example', 'Ảnh tư liệu', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'example.webp', hash('sha256', 'image'), 'image/webp', 5, 1200, 800, 'PUBLIC', ['canonical_filename' => 'example.webp', 'attachment_readback_status' => 'verified']);

        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$asset])))->archive()['items'][0];

        self::assertSame('Ảnh tư liệu trong kho hình ảnh NHK.', $item['summary']);
    }

    public function test_gallery_marks_only_media_without_a_public_image_asset_as_placeholder_candidate(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'example', 'Ảnh tư liệu', 'ready');
        $private = new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'example.webp', hash('sha256', 'image'), 'image/webp', 5, 1200, 800, 'PRIVATE', ['canonical_filename' => 'example.webp', 'attachment_readback_status' => 'verified']);

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
        self::assertStringContainsString('Đọc bài viết', $template);
        self::assertStringContainsString("['article_url']", $template);
        self::assertStringContainsString('data-full-src', $template);
        self::assertStringContainsString('library-note', $template);
        self::assertStringContainsString("['has_real_image']", $template);
    }

    public function test_media_archive_is_thumbnail_first_and_keeps_full_asset_for_explicit_image_action(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/media.php');

        self::assertStringContainsString('nhk_v3_media_presentation($item, true)', $template);
        self::assertStringContainsString("['srcset']", $template);
        self::assertStringContainsString("['sizes']", $template);
        self::assertStringContainsString('data-full-src', $template);
        self::assertStringContainsString('library-image-link', $template);
    }

    public function test_media_template_uses_a_compact_responsive_grid_and_preserves_image_ratio(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/media-video.css');

        self::assertStringContainsString('.media-library-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))', $css);
        self::assertStringContainsString('@media(max-width:64rem){.media-library-grid{grid-template-columns:repeat(2,minmax(0,1fr))', $css);
        self::assertStringContainsString('@media(max-width:36rem){.media-library-grid{grid-template-columns:1fr}', $css);
        self::assertStringContainsString('object-fit:contain', $css);
    }

    public function test_article_album_has_direct_webp_fallback_and_accessible_progressive_lightbox(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $template = (string) file_get_contents($theme . '/single.php');
        $script = (string) file_get_contents($theme . '/album.js');

        self::assertStringContainsString('data-album-open', $template);
        self::assertStringContainsString('<dialog data-album-dialog', $template);
        self::assertStringContainsString('aria-modal="true"', $template);
        self::assertStringContainsString('data-album-dialog-image', $template);
        self::assertStringContainsString('showModal()', $script);
        self::assertStringContainsString("event.key === 'Escape'", $script);
        self::assertStringContainsString('invoker.focus()', $script);
        self::assertStringContainsString('event.key !== \'Tab\'', $script);
    }

    public function test_article_album_is_thumbnail_first_and_hydrates_full_asset_on_demand(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $template = (string) file_get_contents($theme . '/single.php');
        $script = (string) file_get_contents($theme . '/album.js');
        $projection = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Media/PublicMediaGalleryQuery.php');

        self::assertStringContainsString('data-full-src', $template);
        self::assertStringContainsString('nhk_v3_media_presentation', $template);
        self::assertStringContainsString('dataset.fullSrc', $script);
        self::assertStringContainsString("'thumbnail_url'", $projection);
        self::assertStringNotContainsString('->resolve(', $projection);
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
