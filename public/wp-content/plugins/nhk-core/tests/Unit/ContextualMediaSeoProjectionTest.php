<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryPublicQuery;
use NHK\Core\Application\Entity\EntityMediaProjection;
use NHK\Core\Application\Media\{ArticleMediaSeoProjection, MediaService, PublicMediaGalleryQuery, VisualSupportPublicProjection};
use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository, WordPressArticleMediaAdapter};
use NHK\Core\Domain\Dictionary\DictionaryConcept;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaSeoStateRegistry, MediaUsage, VisualSupportRequirement};
use PHPUnit\Framework\TestCase;

final class ContextualMediaSeoProjectionTest extends TestCase
{
    public function test_exact_usage_metadata_wins_per_article_context_and_ignores_other_endpoint_usage(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $item = $service->create('media-a', 'Đồng hồ cúc cu', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/media-a.webp', hash('sha256', 'media-a'), 'image/webp', 10, 1200, 800, 'PUBLIC', ['canonical_filename' => 'media-a.webp']);
        $service->addUsage($item->canonicalId, 'wp_post', '1:54', 'featured_primary', 0, 'Ảnh bài 54', 'Mô tả bài 54', [], 'Bài 54', 'article:1:54:featured_primary');
        $service->addUsage($item->canonicalId, 'wp_post', '1:57', 'featured_primary', 0, 'Ảnh bài 57', 'Mô tả bài 57', [], 'Bài 57', 'article:1:57:featured_primary');
        $service->addUsage($item->canonicalId, 'variant', 'variant-cuckoo', 'representative', 0, 'Ảnh chủ thể', 'Mô tả chủ thể', [], 'Chủ thể');
        $service->addUsage($item->canonicalId, 'dictionary_concept', 'cuckoo-clock', 'representative', 0, 'Ảnh từ điển', 'Mô tả từ điển', [], 'Từ điển');

        $projection = new ArticleMediaSeoProjection($media, $assets, $usages);
        $articleA = $projection->forPost('1:54');
        $articleB = $projection->forPost('1:57');

        self::assertSame('Ảnh bài 54', $articleA['alt']);
        self::assertSame('Bài 54', $articleA['title']);
        self::assertSame('Mô tả bài 54', $articleA['caption']);
        self::assertSame('MEDIA_USAGE', $articleA['metadata_source']);
        self::assertSame('Ảnh bài 57', $articleB['alt']);
        self::assertSame('Bài 57', $articleB['title']);
        self::assertSame('Mô tả bài 57', $articleB['caption']);
        self::assertSame('MEDIA_USAGE', $articleB['metadata_source']);
    }

    public function test_entity_subject_representative_falls_through_per_field_to_neutral_media_metadata(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $item = $service->create('subject-media', 'Tên Media trung tính', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/subject-media.webp', hash('sha256', 'subject-media'), 'image/webp', 10, 1200, 800, 'PUBLIC', ['canonical_filename' => 'subject-media.webp']);
        $service->addUsage($item->canonicalId, 'variant', 'variant-1', 'representative', 0, 'Alt chủ thể', '', [], '');

        $result = (new EntityMediaProjection($media, $assets, $usages))->forEntity('variant', 'variant-1');

        self::assertSame('SUBJECT_REPRESENTATIVE', $result['representative']['metadata_source']);
        self::assertSame('Alt chủ thể', $result['representative']['alt']);
        self::assertSame('Tên Media trung tính', $result['representative']['title']);
        self::assertSame('Tên Media trung tính', $result['representative']['caption']);
    }

    public function test_article_usage_fields_win_over_attachment_and_missing_fields_use_attachment_fallback(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $item = $service->create('attachment-fallback', 'Media name must survive', 'ready');
        $asset = $service->addAsset($item->canonicalId, 'original', 'uploads/attachment-fallback.webp', hash('sha256', 'attachment-fallback'), 'image/webp', 10, 1200, 800, 'PUBLIC', ['canonical_filename' => 'attachment-fallback.webp']);
        $service->addUsage($item->canonicalId, 'wp_post', '1:60', 'featured_primary', 0, 'Alt canonical', '', [], '', 'article:1:60:featured_primary');
        $adapter = new ContextualAttachmentDouble();

        $result = (new ArticleMediaSeoProjection($media, $assets, $usages, $adapter))->forPost('1:60');

        self::assertSame('Alt canonical', $result['alt']);
        self::assertSame('Media name must survive', $result['title']);
        self::assertSame('Original attachment caption', $result['caption']);
        self::assertSame('MEDIA_USAGE', $result['metadata_source']);
        self::assertSame('Media name must survive', $media->findByCanonicalId($item->canonicalId)?->canonicalName);
        self::assertSame(['title' => 'Original attachment title', 'alt' => 'Original attachment alt', 'caption' => 'Original attachment caption'], $adapter->metadata);
        self::assertSame($item->canonicalId, $asset->mediaId);
    }

    public function test_article_rejects_featured_usage_without_the_canonical_placement(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $item = $service->create('missing-placement', 'Missing placement', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/missing-placement.webp', hash('sha256', 'missing-placement'), 'image/webp', 10, 1200, 800, 'PUBLIC', ['canonical_filename' => 'missing-placement.webp']);
        $service->addUsage($item->canonicalId, 'wp_post', '1:64', 'featured_primary', 0, 'Không được chọn');

        $result = (new ArticleMediaSeoProjection($media, $assets, $usages))->forPost('1:64');

        self::assertFalse($result['eligible']);
        self::assertSame(MediaSeoStateRegistry::INCOMPLETE_FEATURED, $result['state']);
        self::assertNull($result['url']);
    }

    public function test_article_ignores_featured_usage_with_an_unrelated_placement(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $item = $service->create('wrong-placement', 'Wrong placement', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/wrong-placement.webp', hash('sha256', 'wrong-placement'), 'image/webp', 10, 1200, 800, 'PUBLIC', ['canonical_filename' => 'wrong-placement.webp']);
        $service->addUsage($item->canonicalId, 'wp_post', '1:62', 'featured_primary', 0, 'Không được chọn', '', [], '', 'article:1:62:inline_primary');

        $result = (new ArticleMediaSeoProjection($media, $assets, $usages))->forPost('1:62');

        self::assertFalse($result['eligible']);
        self::assertSame(MediaSeoStateRegistry::INCOMPLETE_FEATURED, $result['state']);
        self::assertNull($result['url']);
    }

    public function test_article_requires_ready_media_even_when_public_asset_exists(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $item = $service->create('draft-media', 'Draft media', 'draft');
        $service->addAsset($item->canonicalId, 'original', 'uploads/draft-media.webp', hash('sha256', 'draft-media'), 'image/webp', 10, 1200, 800, 'PUBLIC', ['canonical_filename' => 'draft-media.webp']);
        $service->addUsage($item->canonicalId, 'wp_post', '1:63', 'featured_primary', 0, 'Không được công khai', '', [], '', 'article:1:63:featured_primary');

        $result = (new ArticleMediaSeoProjection($media, $assets, $usages))->forPost('1:63');

        self::assertSame(MediaSeoStateRegistry::MISSING, $result['state']);
        self::assertFalse($result['eligible']);
        self::assertNull($result['url']);
        self::assertNull($result['image_url']);
    }

    public function test_private_source_without_public_asset_is_explicit_missing_and_has_no_public_url(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $item = $service->create('private-source', 'Private source', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'private/private-source.webp', hash('sha256', 'private-source'), 'image/webp', 10, 1200, 800, 'PRIVATE', ['canonical_filename' => 'private-source.webp']);
        $service->addUsage($item->canonicalId, 'wp_post', '1:61', 'featured_primary', 0, 'Không được công khai', '', [], '', 'article:1:61:featured_primary');

        $result = (new ArticleMediaSeoProjection($media, $assets, $usages))->forPost('1:61');

        self::assertSame('MISSING', $result['state']);
        self::assertFalse($result['eligible']);
        self::assertNull($result['url']);
        self::assertNull($result['image_url']);
    }

    public function test_gallery_without_subject_context_uses_neutral_media_metadata_without_mutating_global_media_metadata(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $item = $service->create('gallery-context', 'Tên Media toàn cục', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/gallery-context.webp', hash('sha256', 'gallery-context'), 'image/webp', 10, 1200, 800, 'PUBLIC', ['canonical_filename' => 'gallery-context.webp']);
        $service->addUsage($item->canonicalId, 'variant', 'variant-gallery', 'representative', 0, 'Alt ngữ cảnh', 'Chú thích ngữ cảnh', [], 'Tiêu đề ngữ cảnh');
        $service->addUsage($item->canonicalId, 'wp_post', '1:65', 'featured_primary', 0, 'Alt bài', 'NỘI DUNG BÀI KHÔNG ĐƯỢC LEAK', [], 'Bài viết', 'article:1:65:featured_primary');

        $result = (new PublicMediaGalleryQuery($media, $assets, null, $usages))->forMedia($item->canonicalId);

        self::assertSame('Tên Media toàn cục', $result['title']);
        self::assertSame('Tên Media toàn cục', $result['alt']);
        self::assertSame('Tên Media toàn cục', $result['caption']);
        self::assertStringNotContainsString('NỘI DUNG BÀI KHÔNG ĐƯỢC LEAK', $result['summary']);
        self::assertSame('Ảnh tư liệu trong kho hình ảnh NHK.', $result['summary']);
        self::assertSame('MEDIA_NEUTRAL', $result['metadata_source']);
        self::assertTrue($result['eligible']);
        self::assertSame(MediaSeoStateRegistry::COMPLETE, $result['state']);
        self::assertArrayHasKey('image_url', $result);
        self::assertArrayNotHasKey('url', $result);
        self::assertSame('Tên Media toàn cục', $media->findByCanonicalId($item->canonicalId)?->canonicalName);
    }

    public function test_gallery_missing_card_preserves_ineligible_registered_missing_contract(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $item = $service->create('gallery-missing', 'Ảnh đang thiếu', 'ready');

        $result = (new PublicMediaGalleryQuery($media, $assets, null, $usages))->forMedia($item->canonicalId);

        self::assertFalse($result['eligible']);
        self::assertSame(MediaSeoStateRegistry::MISSING, $result['state']);
        self::assertNull($result['image_url']);
        self::assertArrayNotHasKey('url', $result);
        self::assertSame('Ảnh tư liệu trong kho hình ảnh NHK.', $result['summary']);
    }

    public function test_visual_support_uses_neutral_media_metadata_and_private_asset_remains_missing(): void
    {
        $media = new Media('018f5b74-5f0a-7d2e-9a93-c0e7d6dc3341', 'visual-support-media', 'Visual support media', 'ready');
        $requirement = VisualSupportRequirement::create('018f5b74-5f0a-7d2e-9a93-c0e7d6dc3321', 'variant', 'configuration', 'COMPONENT_DETAIL', 'technical_detail')->withResolution($media->canonicalId, $media->revision);
        $private = new MediaAsset('018f5b74-5f0a-7d2e-9a93-c0e7d6dc3351', $media->canonicalId, 'original', 'private/visual-support.jpg', str_repeat('a', 64), 'image/jpeg', 1000, 800, 600, 'PRIVATE');
        $public = new MediaAsset('018f5b74-5f0a-7d2e-9a93-c0e7d6dc3352', $media->canonicalId, 'original', 'visual-support.jpg', str_repeat('b', 64), 'image/jpeg', 1000, 800, 600, 'PUBLIC', ['canonical_filename' => 'visual-support.webp']);

        $result = (new VisualSupportPublicProjection())->resolve($requirement, $media, [$public]);

        self::assertSame('MEDIA_NEUTRAL', $result['metadata_source']);
        self::assertSame('Visual support media', $result['title']);
        self::assertSame('Visual support media', $result['alt']);
        self::assertSame('', $result['caption']);
        self::assertSame('/anh/visual-support.webp', $result['url']);
        self::assertNull((new VisualSupportPublicProjection())->resolve($requirement, $media, [$private]));
    }

    public function test_dictionary_definition_never_becomes_image_metadata(): void
    {
        $concept = new DictionaryConcept('concept-1', 'Đồng hồ cúc cu', 'ĐỊNH NGHĨA NỘI BỘ KHÔNG PHẢI ALT', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'dong-ho-cuc-cu']);
        $repo = new class($concept) implements DictionaryConceptRepository {
            public function __construct(private DictionaryConcept $concept) {}
            public function findById(string $conceptId): ?DictionaryConcept { return $conceptId === $this->concept->conceptId ? $this->concept : null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return [$this->concept]; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $concept; }
            public function addLabel(\NHK\Core\Domain\Dictionary\DictionaryLabel $label): \NHK\Core\Domain\Dictionary\DictionaryLabel { return $label; }
        };

        $result = (new DictionaryPublicQuery($repo, static fn (string $id): array => ['title' => 'Ảnh đại diện', 'alt' => 'Alt hình', 'caption' => 'Chú thích hình']))->detail('dong-ho-cuc-cu');

        self::assertSame('ĐỊNH NGHĨA NỘI BỘ KHÔNG PHẢI ALT', $result['item']['description']);
        self::assertNotContains('ĐỊNH NGHĨA NỘI BỘ KHÔNG PHẢI ALT', array_values($result['item']['image']));
    }

    /** @return array{0:MediaRepository,1:MediaAssetRepository,2:MediaUsageRepository,3:MediaService} */
    private function stores(): array
    {
        $media = new class implements MediaRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Media { return $this->items[$id] ?? null; }
            public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(Media $item): Media { return $this->items[$item->canonicalId] = $item; }
            public function update(Media $item, int $revision): Media { return $this->items[$item->canonicalId] = $item; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $assets = new class implements MediaAssetRepository {
            public array $items = [];
            public function findByAssetId(string $id): ?MediaAsset { return $this->items[$id] ?? null; }
            public function create(MediaAsset $asset): MediaAsset { return $this->items[$asset->assetId] = $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $this->items[$asset->assetId] = $asset; }
            public function listByMediaId(string $id): array { return array_values(array_filter($this->items, static fn (MediaAsset $asset): bool => $asset->mediaId === $id)); }
            public function findByChecksum(string $checksum): array { return array_values(array_filter($this->items, static fn (MediaAsset $asset): bool => $asset->checksum === $checksum)); }
        };
        $usages = new class implements MediaUsageRepository {
            public array $items = [];
            public function create(MediaUsage $usage): MediaUsage { return $this->items[$usage->usageId] = $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->mediaId === $id && ($role === null || $usage->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $type && $usage->endpointKey === $key && ($role === null || $usage->role === $role))); }
        };
        return [$media, $assets, $usages, new MediaService($media, $assets, $usages)];
    }
}

final class ContextualAttachmentDouble implements WordPressArticleMediaAdapter
{
    public array $metadata = ['title' => 'Original attachment title', 'alt' => 'Original attachment alt', 'caption' => 'Original attachment caption'];

    public function read(int $postId): array { return ['featured_media_id' => null, 'inline_media_ids' => [], 'managed_inline_media_id' => null, 'featured_attachment_id' => 0, 'inline_attachment_ids' => [], 'content' => '']; }
    public function synchronize(int $postId, array $result): array { return $this->read($postId); }
    public function attachmentForMedia(Media $media, MediaAsset $asset, string $contextualAlt = '', array $context = []): array
    {
        return ['url' => '/attachment.webp', 'src' => '/attachment.webp', 'srcset' => '/attachment.webp 1200w', 'sizes' => '100vw', 'width' => 1200, 'height' => 800, 'title' => $this->metadata['title'], 'alt' => $this->metadata['alt'], 'caption' => $this->metadata['caption'], 'attachment_id' => 902];
    }
    public function adoptAttachment(int $attachmentId): ?string { return null; }
}
