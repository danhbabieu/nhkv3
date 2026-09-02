<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{ArticleMediaCoordinator, ArticleMediaSeoProjection, MediaBatchIngestService, MediaFilenameNormalizer, MediaIngestGateway, MediaService};
use NHK\Core\Contracts\Media\{ArticleMediaBlueprintRepository, MediaAssetRepository, MediaRepository, MutableMediaUsageRepository, WordPressArticleMediaAdapter};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaSeoBlueprint, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ArticleMediaPolicyTest extends TestCase
{
    public function test_new_post_gets_distinct_placeholders_blueprints_and_idempotent_required_usages(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $first = $coordinator->ensureForPost(42, ['subject' => 'Odo 36/8']);
        $second = $coordinator->ensureForPost(42, ['subject' => 'Odo 36/8']);

        self::assertSame('MEDIA_PLACEHOLDER', $first->state);
        self::assertNotSame($first->slotMedia['featured_primary'], $first->slotMedia['inline_primary']);
        self::assertSame($first->slotMedia, $second->slotMedia);
        self::assertCount(2, $usages->listByEndpoint('wp_post', '1:42'));
        self::assertCount(2, $blueprints->listByPost(42));
        self::assertCount(2, array_filter($media->items, static fn (Media $item): bool => $item->isSystemPlaceholder()));
        self::assertNotEmpty($first->diagnostics);
    }

    public function test_suitable_existing_media_is_reused_without_duplicate_identity(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $featured = $service->create('odo-front', 'Odo 36/8 front', 'ready', ['detail_type' => 'WHOLE_FRONT']);
        $inline = $service->create('odo-dial', 'Odo 36/8 dial', 'ready', ['detail_type' => 'DIAL']);
        $service->addAsset($featured->canonicalId, 'original', 'uploads/odo-front.jpg', hash('sha256', 'front'), 'image/jpeg', 10, 1600, 900, 'PUBLIC');
        $service->addAsset($inline->canonicalId, 'original', 'uploads/odo-dial.jpg', hash('sha256', 'dial'), 'image/jpeg', 10, 1000, 700, 'PUBLIC');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(43, ['subject' => 'Odo 36/8'], ['featured_primary' => $featured->canonicalId, 'inline_primary' => $inline->canonicalId]);

        self::assertSame($featured->canonicalId, $result->slotMedia['featured_primary']);
        self::assertSame($inline->canonicalId, $result->slotMedia['inline_primary']);
        self::assertCount(2, $media->items);
        self::assertSame('MEDIA_COMPLETE', $result->state);
    }

    public function test_mandatory_slots_cannot_share_a_media_identity(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $item = $service->create('one-image', 'One image', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/one.jpg', hash('sha256', 'one'), 'image/jpeg', 3, 1200, 800, 'PUBLIC');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(44, [], ['featured_primary' => $item->canonicalId, 'inline_primary' => $item->canonicalId]);

        self::assertSame($item->canonicalId, $result->slotMedia['featured_primary']);
        self::assertNotSame($item->canonicalId, $result->slotMedia['inline_primary']);
        self::assertTrue($result->slots['inline_primary']['placeholder']);
        self::assertContains('ARTICLE_MEDIA_INLINE_MISSING', array_column($result->diagnostics, 'code'));
    }

    public function test_same_media_supports_different_contextual_usage_text_without_binary_duplication(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $item = $service->create('movement-rear', 'Mặt sau bộ máy', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/movement-rear.jpg', hash('sha256', 'movement-rear'), 'image/jpeg', 3, 1200, 800, 'PUBLIC');
        $service->addUsage($item->canonicalId, 'wp_post', '1:45', 'inline_supporting', 0, 'Mặt sau bộ máy dùng để nhận diện.', 'Ảnh trong bài viết.', ['subject', 'view']);
        $service->addUsage($item->canonicalId, 'specimen', 'specimen-1', 'gallery', 0, 'Mặt sau bộ máy của hiện vật.', 'Ảnh hiện vật.', ['subject', 'part']);

        $articleUsage = $usages->listByEndpoint('wp_post', '1:45')[0];
        $specimenUsage = $usages->listByEndpoint('specimen', 'specimen-1')[0];
        self::assertSame($articleUsage->mediaId, $specimenUsage->mediaId);
        self::assertNotSame($articleUsage->altText, $specimenUsage->altText);
        self::assertCount(1, $assets->listByMediaId($item->canonicalId));
    }

    public function test_filename_normalization_replaces_camera_name_with_stable_descriptive_name(): void
    {
        $filename = (new MediaFilenameNormalizer())->normalize('Odo 36/8', 'Mặt sau bộ máy', 'DSCF8291.JPG', 'a71c');
        self::assertSame('odo-36-8-mat-sau-bo-may-a71c.jpg', $filename);
        self::assertStringNotContainsString('DSCF8291', $filename);
    }

    public function test_unknown_keyword_group_is_rejected_at_media_usage_boundary(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $item = $service->create('keyword-check', 'Keyword check', 'ready');
        $this->expectException(\NHK\Core\Domain\Media\InvalidMedia::class);
        $service->addUsage($item->canonicalId, 'wp_post', '1:46', 'inline_supporting', 0, 'Alt', '', ['uncontrolled-tag']);
    }

    public function test_replacing_placeholder_repoints_usage_without_overwriting_placeholder_media(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);
        $initial = $coordinator->ensureForPost(47);
        $real = $service->create('replacement-front', 'Replacement front', 'ready');
        $service->addAsset($real->canonicalId, 'original', 'uploads/replacement.jpg', hash('sha256', 'replacement'), 'image/jpeg', 11, 1600, 900, 'PUBLIC');

        $result = $coordinator->ensureForPost(47, [], ['featured_primary' => $real->canonicalId]);

        self::assertSame($real->canonicalId, $result->slotMedia['featured_primary']);
        self::assertNotSame($initial->slotMedia['featured_primary'], $result->slotMedia['featured_primary']);
        self::assertTrue($media->findByCanonicalId($initial->slotMedia['featured_primary'])?->isSystemPlaceholder());
        self::assertCount(2, $usages->listByEndpoint('wp_post', '1:47'));
    }

    public function test_placeholder_and_private_assets_are_excluded_from_preferred_image_projection(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);
        $coordinator->ensureForPost(48);
        $projection = new ArticleMediaSeoProjection($media, $assets, $usages);
        self::assertFalse($projection->isImageSitemapEligible('1:48'));

        $real = $service->create('public-featured', 'Public featured', 'ready');
        $service->addAsset($real->canonicalId, 'original', 'uploads/public-featured.jpg', hash('sha256', 'public-featured'), 'image/jpeg', 14, 1600, 900, 'PUBLIC');
        $coordinator->ensureForPost(48, [], ['featured_primary' => $real->canonicalId]);
        self::assertTrue($projection->isImageSitemapEligible('1:48'));
    }

    public function test_coordinator_synchronizes_canonical_slots_to_wordpress_editorial_state(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $featured = $service->create('bridge-featured', 'Bridge featured', 'ready');
        $inline = $service->create('bridge-inline', 'Bridge inline', 'ready');
        $service->addAsset($featured->canonicalId, 'original', 'uploads/bridge-featured.jpg', hash('sha256', 'bridge-featured'), 'image/jpeg', 10, 1600, 900, 'PUBLIC');
        $service->addAsset($inline->canonicalId, 'original', 'uploads/bridge-inline.jpg', hash('sha256', 'bridge-inline'), 'image/jpeg', 10, 1200, 800, 'PUBLIC');
        $adapter = new class implements WordPressArticleMediaAdapter {
            public array $synced = [];
            public function read(int $postId): array { return ['featured_media_id' => null, 'inline_media_ids' => [], 'managed_inline_media_id' => null, 'featured_attachment_id' => 0, 'inline_attachment_ids' => [], 'content' => '']; }
            public function synchronize(int $postId, array $result): array { $this->synced[] = [$postId, $result]; return $this->read($postId); }
            public function attachmentForMedia(Media $media, MediaAsset $asset, string $contextualAlt = '', array $context = []): array { return []; }
            public function adoptAttachment(int $attachmentId): ?string { return null; }
        };
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1, $adapter);

        $result = $coordinator->ensureForPost(49, [], ['featured_primary' => $featured->canonicalId, 'inline_primary' => $inline->canonicalId]);

        self::assertCount(1, $adapter->synced);
        self::assertSame(49, $adapter->synced[0][0]);
        self::assertSame($featured->canonicalId, $adapter->synced[0][1]['slot_media']['featured_primary']);
        self::assertSame($inline->canonicalId, $adapter->synced[0][1]['slot_media']['inline_primary']);
        self::assertSame('MEDIA_COMPLETE', $result->state);
    }

    public function test_seo_projection_uses_the_wordpress_attachment_representation(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $item = $service->create('seo-bridge-featured', 'SEO bridge featured', 'ready');
        $asset = $service->addAsset($item->canonicalId, 'original', 'uploads/seo-bridge.jpg', hash('sha256', 'seo-bridge'), 'image/jpeg', 10, 1600, 900, 'PUBLIC');
        $service->addUsage($item->canonicalId, 'wp_post', '1:50', 'featured_primary', 0, 'Ảnh mặt trước');
        $adapter = new class implements WordPressArticleMediaAdapter {
            public function read(int $postId): array { return ['featured_media_id' => null, 'inline_media_ids' => [], 'managed_inline_media_id' => null, 'featured_attachment_id' => 0, 'inline_attachment_ids' => [], 'content' => '']; }
            public function synchronize(int $postId, array $result): array { return $this->read($postId); }
            public function attachmentForMedia(Media $media, MediaAsset $asset, string $contextualAlt = '', array $context = []): array { return ['url' => 'https://cdn.example.test/seo-bridge.jpg', 'src' => 'https://cdn.example.test/seo-bridge.jpg', 'srcset' => 'https://cdn.example.test/seo-bridge.jpg 1600w', 'sizes' => '100vw', 'width' => 1600, 'height' => 900, 'alt' => $contextualAlt, 'attachment_id' => 901]; }
            public function adoptAttachment(int $attachmentId): ?string { return null; }
        };

        $projection = new ArticleMediaSeoProjection($media, $assets, $usages, $adapter);
        $result = $projection->forPost('1:50');

        self::assertTrue($result['eligible']);
        self::assertSame('https://cdn.example.test/seo-bridge.jpg', $result['image_url']);
        self::assertSame('100vw', $result['sizes']);
        self::assertSame('Ảnh mặt trước', $result['alt']);
    }

    public function test_bulk_ingest_uses_one_batch_context_but_keeps_media_independently_reviewable(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $bulk = new MediaBatchIngestService(new MediaIngestGateway($service));
        $result = $bulk->ingest('admin-upload', 'operator-1', ['specimen' => 'candidate-1'], [
            ['stable_key' => 'batch-front', 'name' => 'Batch front', 'readiness' => 'draft', 'assets' => [['kind' => 'original', 'storage_key' => 'uploads/front.jpg', 'checksum' => hash('sha256', 'front'), 'mime_type' => 'image/jpeg', 'byte_size' => 5]], 'batch_context' => ['view' => 'WHOLE_FRONT']],
            ['stable_key' => 'batch-rear', 'name' => 'Batch rear', 'readiness' => 'draft', 'assets' => [['kind' => 'original', 'storage_key' => 'uploads/rear.jpg', 'checksum' => hash('sha256', 'rear'), 'mime_type' => 'image/jpeg', 'byte_size' => 4],], 'batch_context' => ['view' => 'WHOLE_REAR']],
        ]);

        self::assertSame('completed', $result['batch']['status']);
        self::assertCount(2, $result['items']);
        self::assertNotSame($result['items'][0]['media_id'], $result['items'][1]['media_id']);
        self::assertSame([], $usages->listByEndpoint('specimen', 'candidate-1'));
    }

    /** @return array{0:object,1:object,2:object,3:object,4:MediaService} */
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
        $usages = new class implements MutableMediaUsageRepository {
            public array $items = [];
            public function create(MediaUsage $usage): MediaUsage { return $this->items[$usage->usageId] = $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->mediaId === $id && ($role === null || $usage->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $type && $usage->endpointKey === $key && ($role === null || $usage->role === $role))); }
            public function removeByEndpointRole(string $type, string $key, string $role): int { $before = count($this->items); foreach ($this->items as $id => $usage) if ($usage->endpointType === $type && $usage->endpointKey === $key && $usage->role === $role) unset($this->items[$id]); return $before - count($this->items); }
        };
        $blueprints = new class implements ArticleMediaBlueprintRepository {
            public array $items = [];
            public function findByPostAndSlot(int $postId, string $slot): ?MediaSeoBlueprint { return $this->items[$postId . ':' . $slot] ?? null; }
            public function save(MediaSeoBlueprint $blueprint): MediaSeoBlueprint { return $this->items[$blueprint->postId . ':' . $blueprint->slot] = $blueprint; }
            public function listByPost(int $postId): array { return array_values(array_filter($this->items, static fn (MediaSeoBlueprint $blueprint): bool => $blueprint->postId === $postId)); }
        };
        return [$media, $assets, $usages, $blueprints, new MediaService($media, $assets, $usages)];
    }
}
