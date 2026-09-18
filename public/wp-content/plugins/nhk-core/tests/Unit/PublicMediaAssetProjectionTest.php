<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{PublicMediaAssetSelector, PublicMediaGalleryQuery};
use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Domain\Media\{MediaSeoStateRegistry, MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Infrastructure\Media\WordPressMediaAttachmentIngestor;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class PublicMediaAssetProjectionTest extends TestCase
{
    public function test_public_projection_uses_large_source_derived_asset_for_canonical_image(): void
    {
        $mediaId = UuidCodec::newV7();
        $source = $this->asset($mediaId, 'original', 'uploads/source-original.jpg', 'source', 2400, 3400, 'PRIVATE', ['source_original' => true]);
        $thumbnail = $this->asset($mediaId, 'derivative', 'uploads/mat-truoc-odo-36-10-thung-kinh-qua-chuong-dep-240x340.webp', 'thumbnail', 240, 340, 'PUBLIC', ['thumbnail' => true]);
        $large = $this->asset($mediaId, 'derivative', 'uploads/mat-truoc-odo-36-10-thung-kinh-qua-chuong-dep.webp', 'large', 847, 1200, 'PUBLIC', ['derived_from' => 'uploads/source-original.jpg', 'canonical_filename' => 'mat-truoc-odo-36-10-thung-kinh-qua-chuong-dep.webp']);

        $selected = (new PublicMediaAssetSelector())->canonical([$thumbnail, $source, $large]);

        self::assertSame($large->assetId, $selected?->assetId);
        self::assertLessThanOrEqual(1200, max($selected?->width ?? 0, $selected?->height ?? 0));
        self::assertEqualsWithDelta(2400 / 3400, ($selected?->width ?? 0) / ($selected?->height ?? 1), 0.001);
    }

    public function test_public_projection_does_not_fallback_to_small_derivative_when_source_is_large(): void
    {
        $mediaId = UuidCodec::newV7();
        $source = $this->asset($mediaId, 'original', 'uploads/source-original.jpg', 'source', 2400, 3400, 'PRIVATE', ['source_original' => true]);
        $thumbnail = $this->asset($mediaId, 'derivative', 'uploads/image-240x340.webp', 'thumbnail', 240, 340, 'PUBLIC', ['thumbnail' => true]);

        self::assertNull((new PublicMediaAssetSelector())->canonical([$source, $thumbnail]));
    }

    public function test_gallery_canonical_url_and_dimensions_are_from_large_public_asset(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'odo-36-10-image', 'Mặt trước Ô Đô 36/10', 'ready');
        $source = $this->asset($mediaId, 'original', 'uploads/source-original.jpg', 'source', 2400, 3400, 'PRIVATE');
        $thumbnail = $this->asset($mediaId, 'derivative', 'uploads/image-240x340.webp', 'thumbnail', 240, 340, 'PUBLIC', ['thumbnail' => true]);
        $large = $this->asset($mediaId, 'derivative', 'uploads/mat-truoc-odo-36-10-thung-kinh-qua-chuong-dep.webp', 'large', 847, 1200, 'PUBLIC', ['canonical_filename' => 'mat-truoc-odo-36-10-thung-kinh-qua-chuong-dep.webp']);

        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$source, $thumbnail, $large])))->archive()['items'][0];

        self::assertSame('/anh/mat-truoc-odo-36-10-thung-kinh-qua-chuong-dep.webp', parse_url((string) $item['image_url'], PHP_URL_PATH));
        self::assertSame(847, $item['width']);
        self::assertSame(1200, $item['height']);
    }

    public function test_public_image_sizing_keeps_small_images_and_downscales_only_large_images(): void
    {
        $cases = [
            [360, 480, 360, 480],
            [480, 360, 480, 360],
            [900, 1200, 900, 1200],
            [1200, 900, 1200, 900],
            [1200, 1200, 1200, 1200],
            [1600, 1200, 1200, 900],
            [1200, 1600, 900, 1200],
            [2400, 1600, 1200, 800],
            [1600, 2400, 800, 1200],
        ];
        foreach ($cases as [$width, $height, $expectedWidth, $expectedHeight]) {
            $result = WordPressMediaAttachmentIngestor::constrainDimensions($width, $height);
            self::assertSame(['width' => $expectedWidth, 'height' => $expectedHeight], $result, $width . 'x' . $height);
            self::assertLessThanOrEqual(1200, max($result['width'], $result['height']));
            self::assertEqualsWithDelta($width / $height, $result['width'] / $result['height'], 0.001);
            if (max($width, $height) <= 1200) self::assertSame([$width, $height], [$result['width'], $result['height']]);
        }
        self::assertGreaterThanOrEqual(82, PublicMediaAssetSelector::DEFAULT_WEBP_QUALITY);
        self::assertLessThanOrEqual(88, PublicMediaAssetSelector::DEFAULT_WEBP_QUALITY);
    }

    public function test_public_projection_rejects_an_oversized_canonical_candidate(): void
    {
        $mediaId = UuidCodec::newV7();
        $oversized = $this->asset($mediaId, 'derivative', 'uploads/old-2048.webp', 'old', 2048, 1365, 'PUBLIC');

        self::assertNull((new PublicMediaAssetSelector())->canonical([$oversized]));
    }

    public function test_gallery_exposes_only_public_delivery_path_and_reports_missing_without_public_asset(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'private-source-proof', 'Ảnh có nguồn riêng tư', 'ready');
        $private = $this->asset($mediaId, 'original', 'private/source-original.jpg', 'private-source', 2400, 1600, 'PRIVATE', ['source_original' => true]);
        $public = $this->asset($mediaId, 'derivative', 'uploads/public-proof.webp', 'public-derivative', 1200, 800, 'PUBLIC', ['canonical_filename' => 'public-proof.webp']);
        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$private, $public])))->archive()['items'][0];

        self::assertSame(MediaSeoStateRegistry::COMPLETE, $item['state']);
        self::assertTrue($item['eligible']);
        self::assertSame('/anh/public-proof.webp', parse_url((string) $item['image_url'], PHP_URL_PATH));
        self::assertStringNotContainsString('private/', (string) $item['image_url']);
        self::assertStringNotContainsString('source-original', (string) $item['image_url']);

        $withoutPublicAsset = new Media(UuidCodec::newV7(), 'private-only', 'Private only', 'ready');
        $missing = (new PublicMediaGalleryQuery($this->mediaRepository([$withoutPublicAsset]), $this->assetRepository([
            $this->asset($withoutPublicAsset->canonicalId, 'original', 'private/only.jpg', 'private-only', 1200, 800, 'PRIVATE'),
        ])))->archive()['items'][0];

        self::assertSame(MediaSeoStateRegistry::MISSING, $missing['state']);
        self::assertFalse($missing['eligible']);
        self::assertNull($missing['image_url']);
    }

    public function test_gallery_uses_approved_contextual_usage_text_without_article_usage_leakage(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'neutral-media-name', 'Neutral media name', 'ready');
        $asset = $this->asset($mediaId, 'derivative', 'uploads/contextual.webp', 'contextual', 800, 600, 'PUBLIC', ['canonical_filename' => 'contextual.webp']);
        $usage = new MediaUsage('018f5b74-5f0a-7d2e-9a93-c0e7d6dc3381', $mediaId, 'entity', 'entity-1', MediaUsageRoleRegistry::REPRESENTATIVE, 1, 'Approved contextual alt', 'Approved contextual caption', [], 'Approved contextual title');
        $articleUsage = new MediaUsage('018f5b74-5f0a-7d2e-9a93-c0e7d6dc3382', $mediaId, 'wp_post', '99', MediaUsageRoleRegistry::FEATURED_PRIMARY, 0, 'Article alt must not leak', 'Article caption must not leak', [], 'Article title must not leak', 1, 'article:99:featured_primary');

        $item = (new PublicMediaGalleryQuery($this->mediaRepository([$media]), $this->assetRepository([$asset]), null, new PublicMediaUsageMemoryRepository([$usage, $articleUsage])))->archive()['items'][0];

        self::assertSame('Approved contextual title', $item['title']);
        self::assertSame('Approved contextual alt', $item['alt']);
        self::assertSame('Approved contextual caption', $item['caption']);
        self::assertSame('SUBJECT_REPRESENTATIVE', $item['metadata_source']);
        self::assertStringNotContainsString('Article', $item['title'] . $item['alt'] . $item['caption']);
    }

    /** @param array<string,mixed> $metadata */
    private function asset(string $mediaId, string $kind, string $storageKey, string $seed, int $width, int $height, string $visibility, array $metadata = []): MediaAsset
    {
        return new MediaAsset(UuidCodec::newV7(), $mediaId, $kind, $storageKey, hash('sha256', $seed), 'image/webp', 100, $width, $height, $visibility, $metadata);
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
}

final class PublicMediaUsageMemoryRepository implements MediaUsageRepository
{
    /** @param list<MediaUsage> $usages */
    public function __construct(private array $usages) {}
    public function listByMediaId(string $mediaId, ?string $role = null): array { return array_values(array_filter($this->usages, static fn (MediaUsage $usage): bool => $usage->mediaId === $mediaId && ($role === null || $usage->role === $role))); }
    public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return []; }
    public function create(MediaUsage $usage): MediaUsage { return $usage; }
}
