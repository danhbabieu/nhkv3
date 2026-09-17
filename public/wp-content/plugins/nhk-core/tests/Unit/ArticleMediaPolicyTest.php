<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{ArticleMediaCoordinator, ArticleMediaSeoProjection, MediaBatchIngestService, MediaFilenameNormalizer, MediaIngestGateway, MediaService};
use NHK\Core\Contracts\Media\{ArticleMediaBlueprintRepository, MediaAssetRepository, MediaRepository, MediaUsageUpdater, MutableMediaUsageRepository, WordPressArticleMediaAdapter};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaException, MediaSeoBlueprint, MediaUsage};
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
        self::assertSame($first->slots['featured_primary']['placement_anchor'], $second->slots['featured_primary']['placement_anchor']);
        self::assertNotSame($first->slots['featured_primary']['placement_anchor'], $first->slots['inline_primary']['placement_anchor']);
        self::assertCount(2, $usages->listByEndpoint('wp_post', '1:42'));
        self::assertCount(2, $blueprints->listByPost(42));
        self::assertCount(2, array_filter($media->items, static fn (Media $item): bool => $item->isSystemPlaceholder()));
        self::assertNotEmpty($first->diagnostics);
    }

    public function test_missing_featured_media_exposes_conversational_guidance_without_opening_publication_gate(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $result = (new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1))->ensureForPost(44, [
            'subject' => 'Đồng hồ Odo 24 Odo 57',
            'preferred_view' => 'WHOLE_FRONT',
            'preferred_aspect' => '16:9',
            'video_thumbnail_fallback' => [
                'eligible' => true,
                'url' => 'https://i.ytimg.com/vi/VwP1AH9E3HA/maxresdefault.jpg',
                'variant' => 'maxresdefault',
                'width' => 1920,
                'height' => 1080,
                'adopted_as_media' => false,
            ],
        ]);

        self::assertTrue($result->guidance['featured_image_missing']);
        self::assertTrue($result->guidance['inline_image_missing']);
        self::assertStringContainsString('chưa có ảnh đại diện riêng', $result->guidance['user_message']);
        self::assertSame('WHOLE_FRONT', $result->guidance['preferred_view']);
        self::assertTrue($result->guidance['video_thumbnail_fallback']['eligible']);
        self::assertFalse($result->guidance['video_thumbnail_fallback']['adopted_as_media']);
        self::assertSame('MEDIA_PLACEHOLDER', $result->state);
    }

    public function test_empty_subject_uses_resolved_canonical_subject_for_blueprint_generation(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();

        $result = (new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1))->ensureForPost(45, [
            'subject' => '',
            'subject_ids' => ['subject-45'],
            'subject_context' => ['subject' => '', 'subject_ids' => ['subject-45']],
            'subject_resolution' => [
                'status' => 'resolved',
                'primary' => ['id' => 'subject-45', 'type' => 'classification', 'name' => 'Đồng hồ tháp'],
            ],
        ]);

        self::assertSame('MEDIA_PLACEHOLDER', $result->state);
        self::assertSame('Đồng hồ tháp', $blueprints->findByPostAndSlot(45, 'featured_primary')?->subjectContext['subject']);
    }

    public function test_suitable_existing_media_is_reused_without_duplicate_identity(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $featured = $service->create('odo-front', 'Odo 36/8 front', 'ready', ['detail_type' => 'WHOLE_FRONT']);
        $inline = $service->create('odo-dial', 'Odo 36/8 dial', 'ready', ['detail_type' => 'DIAL']);
        $service->addAsset($featured->canonicalId, 'original', 'uploads/odo-front.jpg', hash('sha256', 'front'), 'image/jpeg', 10, 1200, 675, 'PUBLIC');
        $service->addAsset($inline->canonicalId, 'original', 'uploads/odo-dial.jpg', hash('sha256', 'dial'), 'image/jpeg', 10, 1000, 700, 'PUBLIC');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(43, ['subject' => 'Odo 36/8'], ['featured_primary' => $featured->canonicalId, 'inline_primary' => $inline->canonicalId]);

        self::assertSame($featured->canonicalId, $result->slotMedia['featured_primary']);
        self::assertSame($inline->canonicalId, $result->slotMedia['inline_primary']);
        self::assertCount(2, $media->items);
        self::assertSame('MEDIA_COMPLETE', $result->state);
    }

    public function test_capture_subject_scope_rejects_high_quality_sibling_before_wordpress_sync(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $sibling = $service->create('odo-36-10-front', 'Odo 36/10 front', 'ready', [
            'metadata' => ['subject_id' => '95873bfe-d978-4eda-a5a2-ce9ba79625df'],
            'detail_type' => 'WHOLE_FRONT',
        ]);
        $service->addAsset($sibling->canonicalId, 'original', 'uploads/odo-36-10.webp', hash('sha256', 'odo-36-10'), 'image/webp', 10, 2400, 1600, 'PUBLIC');
        $adapter = new class implements WordPressArticleMediaAdapter {
            public array $syncs = [];
            public function read(int $postId): array { return ['featured_media_id' => null, 'inline_media_ids' => [], 'managed_inline_media_id' => null, 'featured_attachment_id' => 0, 'inline_attachment_ids' => [], 'content' => '']; }
            public function synchronize(int $postId, array $result): array { $this->syncs[] = $result; return $this->read($postId); }
            public function attachmentForMedia(Media $media, MediaAsset $asset, string $contextualAlt = '', array $context = []): array { throw new \LogicException('Sibling Media must not reach attachment sync.'); }
            public function adoptAttachment(int $attachmentId): ?string { return null; }
        };
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1, $adapter);

        $result = $coordinator->ensureForPost(902, [
            'capture_id' => 'capture-odo-36-8',
            'subject_ids' => ['852da54d-457a-4397-a16d-52d9452ba766'],
            'subject_scope_locked' => true,
            'allow_scoped_reuse' => true,
            'allow_unscoped_reuse' => false,
        ]);

        self::assertNotSame($sibling->canonicalId, $result->slotMedia['featured_primary']);
        self::assertNotSame($sibling->canonicalId, $result->slotMedia['inline_primary']);
        self::assertTrue($media->findByCanonicalId($result->slotMedia['featured_primary'])?->isSystemPlaceholder());
        self::assertTrue($media->findByCanonicalId($result->slotMedia['inline_primary'])?->isSystemPlaceholder());
        self::assertSame($result->slotMedia['featured_primary'], $adapter->syncs[0]['slot_media']['featured_primary']);
        self::assertSame($result->slotMedia['inline_primary'], $adapter->syncs[0]['slot_media']['inline_primary']);
    }

    public function test_generic_sibling_fixture_is_rejected_before_ranking_even_when_it_has_more_history(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $variantA = $service->create('variant-a-front', 'Variant A front', 'ready', ['metadata' => ['subject_id' => 'variant-a']]);
        $variantB = $service->create('variant-b-front', 'Variant B front', 'ready', ['metadata' => ['subject_id' => 'variant-b'], 'detail_type' => 'WHOLE_FRONT']);
        foreach ([[$variantA, 'variant-a'], [$variantB, 'variant-b']] as [$item, $stem]) {
            $service->addAsset($item->canonicalId, 'original', 'uploads/' . $stem . '.webp', hash('sha256', $stem), 'image/webp', 10, 2400, 1600, 'PUBLIC');
        }
        $service->addUsage($variantA->canonicalId, 'variant', 'variant-a', 'representative');
        foreach (range(1, 4) as $index) $service->addUsage($variantB->canonicalId, 'wp_post', '1:' . (700 + $index), 'inline_primary', 0, 'Historical sibling use');
        $service->addUsage($variantB->canonicalId, 'variant', 'variant-b', 'representative');

        $result = (new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1))->ensureForPost(777, [
            'capture_id' => 'capture-generic-a',
            'subject_ids' => ['variant-a'],
            'subject_context' => ['subject_ids' => ['variant-a']],
            'subject_scope_locked' => true,
            'allow_scoped_reuse' => true,
            'allow_unscoped_reuse' => false,
        ]);

        self::assertSame($variantA->canonicalId, $result->slotMedia['featured_primary']);
        self::assertSame($variantA->canonicalId, $result->slotMedia['inline_primary']);
        self::assertNotContains($variantB->canonicalId, $result->slotMedia);
    }

    public function test_generic_parent_scoped_media_is_not_authorized_for_exact_variant(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $parent = $service->create('model-parent-front', 'Model parent front', 'ready', ['metadata' => ['subject_id' => 'model-parent']]);
        $service->addAsset($parent->canonicalId, 'original', 'uploads/model-parent.webp', hash('sha256', 'model-parent'), 'image/webp', 10, 2400, 1600, 'PUBLIC');
        $service->addUsage($parent->canonicalId, 'model', 'model-parent', 'representative');

        $result = (new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1))->ensureForPost(778, [
            'capture_id' => 'capture-generic-child',
            'subject_ids' => ['variant-child'],
            'subject_scope_locked' => true,
            'allow_scoped_reuse' => true,
            'allow_unscoped_reuse' => false,
        ]);

        self::assertTrue($result->slots['featured_primary']['placeholder']);
        self::assertTrue($result->slots['inline_primary']['placeholder']);
        self::assertNotContains($parent->canonicalId, $result->slotMedia);
    }

    public function test_text_only_capture_without_assets_does_not_adopt_unrelated_media(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $unrelated = $service->create('odo-36-10', 'Odo 36/10', 'ready');
        $service->addAsset($unrelated->canonicalId, 'original', 'uploads/odo-36-10.jpg', hash('sha256', 'odo-36-10'), 'image/jpeg', 10, 1200, 800, 'PUBLIC');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(342, ['subject' => 'Odo 30', 'capture_has_physical_assets' => false, 'allow_unscoped_reuse' => false]);

        self::assertNotContains($unrelated->canonicalId, $result->slotMedia);
        self::assertSame('MEDIA_PLACEHOLDER', $result->state);
        self::assertNotSame('MEDIA_COMPLETE', $result->state);
    }

    public function test_text_only_capture_reconciles_stale_wordpress_media_to_placeholders(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $unrelated = $service->create('odo-36-10-stale', 'Odo 36/10 stale', 'ready');
        $service->addAsset($unrelated->canonicalId, 'original', 'uploads/odo-36-10-stale.jpg', hash('sha256', 'stale'), 'image/jpeg', 10, 1200, 800, 'PUBLIC');
        $adapter = new class($unrelated->canonicalId) implements WordPressArticleMediaAdapter {
            public function __construct(private string $mediaId) {}
            public function read(int $postId): array { return ['featured_media_id' => $this->mediaId, 'inline_media_ids' => [$this->mediaId], 'managed_inline_media_id' => 0, 'featured_attachment_id' => 101, 'inline_attachment_ids' => [101], 'content' => '<img src="stale">']; }
            public function synchronize(int $postId, array $result): array { return ['featured_media_id' => null, 'inline_media_ids' => [], 'managed_inline_media_id' => 0, 'featured_attachment_id' => 0, 'inline_attachment_ids' => [], 'content' => '']; }
            public function attachmentForMedia(Media $media, MediaAsset $asset, string $contextualAlt = '', array $context = []): array { return []; }
            public function adoptAttachment(int $attachmentId): ?string { return null; }
        };
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1, $adapter);

        $result = $coordinator->ensureForPost(342, ['subject' => 'Odo 30', 'capture_has_physical_assets' => false, 'allow_unscoped_reuse' => false]);

        self::assertNotContains($unrelated->canonicalId, $result->slotMedia);
        self::assertSame('MEDIA_PLACEHOLDER', $result->state);
    }

    public function test_capture_without_files_never_reuses_current_wordpress_media_without_persisted_subject_scope(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $stale = $service->create('odo-36-10-stale-unscoped', 'Serial 6421 trên vách máy', 'ready');
        $service->addAsset($stale->canonicalId, 'original', 'uploads/stale.jpg', hash('sha256', 'stale-unscoped'), 'image/jpeg', 10, 1200, 800, 'PUBLIC');
        $adapter = new class($stale->canonicalId) implements WordPressArticleMediaAdapter {
            public function __construct(private string $mediaId) {}
            public function read(int $postId): array { return ['featured_media_id' => $this->mediaId, 'inline_media_ids' => [$this->mediaId], 'state_token' => 'state-355']; }
            public function synchronize(int $postId, array $result): array { return ['featured_media_id' => null, 'inline_media_ids' => [], 'state_token' => 'state-356']; }
            public function attachmentForMedia(Media $media, MediaAsset $asset, string $contextualAlt = '', array $context = []): array { return []; }
            public function adoptAttachment(int $attachmentId): ?string { return null; }
        };
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1, $adapter);

        $result = $coordinator->ensureForPost(355, [
            'subject' => 'Đồng hồ Odo 36/10',
            'subject_ids' => ['95873bfe-d978-4eda-a5a2-ce9ba79625df'],
            'capture_has_physical_assets' => false,
            'allow_unscoped_reuse' => true,
        ]);

        self::assertNotContains($stale->canonicalId, $result->slotMedia);
        self::assertTrue($result->slots['featured_primary']['placeholder']);
        self::assertTrue($result->slots['inline_primary']['placeholder']);
        self::assertSame('MEDIA_PLACEHOLDER', $result->state);
    }

    public function test_stale_wordpress_readback_cannot_reintroduce_media_without_subject_scope_proof(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $stale = $service->create('odo-36-10-stale-readback', 'Stale readback', 'ready');
        $service->addAsset($stale->canonicalId, 'original', 'uploads/stale-readback.jpg', hash('sha256', 'stale-readback'), 'image/jpeg', 10, 1200, 800, 'PUBLIC');
        $adapter = new class($stale->canonicalId) implements WordPressArticleMediaAdapter {
            public function __construct(private string $mediaId) {}
            public function read(int $postId): array { return ['featured_media_id' => $this->mediaId, 'inline_media_ids' => [$this->mediaId], 'state_token' => 'state-355']; }
            public function synchronize(int $postId, array $result): array { return ['featured_media_id' => $this->mediaId, 'inline_media_ids' => [$this->mediaId], 'state_token' => 'state-355']; }
            public function attachmentForMedia(Media $media, MediaAsset $asset, string $contextualAlt = '', array $context = []): array { return []; }
            public function adoptAttachment(int $attachmentId): ?string { return null; }
        };
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1, $adapter);

        $result = $coordinator->ensureForPost(355, [
            'subject' => 'Đồng hồ Odo 36/10',
            'subject_ids' => ['95873bfe-d978-4eda-a5a2-ce9ba79625df'],
            'capture_has_physical_assets' => false,
            'allow_scoped_reuse' => true,
        ]);

        self::assertNotContains($stale->canonicalId, $result->slotMedia);
        self::assertSame('MEDIA_PLACEHOLDER', $result->state);
    }

    public function test_capture_subject_scope_replaces_wrong_variant_with_existing_correct_variant_media(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $wrong = $service->create('odo-36-10-scoped', 'Odo 36/10 image', 'ready', ['metadata' => ['subject_id' => 'variant-36-10']]);
        $right = $service->create('odo-36-8-scoped', 'Odo 36/8 Westminster image', 'ready', ['metadata' => ['subject_id' => 'variant-36-8']]);
        $service->addAsset($wrong->canonicalId, 'original', 'uploads/odo-36-10-scoped.jpg', hash('sha256', 'wrong-scoped'), 'image/jpeg', 10, 1200, 800, 'PUBLIC');
        $service->addAsset($right->canonicalId, 'original', 'uploads/odo-36-8-scoped.jpg', hash('sha256', 'right-scoped'), 'image/jpeg', 10, 1200, 800, 'PUBLIC');
        $service->addUsage($wrong->canonicalId, 'wp_post', '1:339', 'featured_primary');
        $service->addUsage($wrong->canonicalId, 'wp_post', '1:339', 'inline_primary');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(339, [
            'subject' => 'Odo 36/8 Westminster',
            'subject_ids' => ['variant-36-8'],
            'subject_context' => ['subject' => 'Odo 36/8 Westminster', 'subject_ids' => ['variant-36-8']],
            'allow_unscoped_reuse' => true,
        ], ['featured_primary' => $wrong->canonicalId, 'inline_primary' => $wrong->canonicalId]);

        self::assertSame($right->canonicalId, $result->slotMedia['featured_primary']);
        self::assertSame($right->canonicalId, $result->slotMedia['inline_primary']);
        self::assertNotContains($wrong->canonicalId, array_map(static fn (MediaUsage $usage): string => $usage->mediaId, $usages->listByEndpoint('wp_post', '1:339')));
        self::assertSame('MEDIA_COMPLETE', $result->state);
    }

    public function test_capture_subject_scope_keeps_article_missing_when_no_correct_variant_media_exists(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $wrong = $service->create('odo-36-10-only', 'Odo 36/10 only image', 'ready', ['metadata' => ['subject_id' => 'variant-36-10']]);
        $service->addAsset($wrong->canonicalId, 'original', 'uploads/odo-36-10-only.jpg', hash('sha256', 'wrong-only'), 'image/jpeg', 10, 1200, 800, 'PUBLIC');
        $service->addUsage($wrong->canonicalId, 'wp_post', '1:339', 'featured_primary');
        $service->addUsage($wrong->canonicalId, 'wp_post', '1:339', 'inline_primary');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(339, [
            'subject' => 'Odo 36/8 Westminster',
            'subject_ids' => ['variant-36-8'],
            'subject_context' => ['subject' => 'Odo 36/8 Westminster', 'subject_ids' => ['variant-36-8']],
            'allow_unscoped_reuse' => true,
        ], ['featured_primary' => $wrong->canonicalId, 'inline_primary' => $wrong->canonicalId]);

        self::assertSame('MEDIA_PLACEHOLDER', $result->state);
        self::assertNotContains($wrong->canonicalId, $result->slotMedia);
        self::assertNotContains($wrong->canonicalId, array_map(static fn (MediaUsage $usage): string => $usage->mediaId, $usages->listByEndpoint('wp_post', '1:339')));
    }

    public function test_capture_with_physical_assets_cannot_adopt_historical_media_from_parent_or_sibling_variant(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $historical = $service->create('odo-36-10-historical', 'Mặt trước Odo 36/10', 'ready', ['metadata' => ['subject_id' => '852da54d-457a-4397-a16d-52d9452ba766-wrong']]);
        $service->addAsset($historical->canonicalId, 'original', 'uploads/odo-36-10-historical.webp', hash('sha256', 'odo-36-10-historical'), 'image/webp', 10, 1200, 800, 'PUBLIC');
        $service->addUsage($historical->canonicalId, 'wp_post', '1:408', 'featured_primary');
        $service->addUsage($historical->canonicalId, 'wp_post', '1:408', 'inline_primary');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(408, [
            'capture_id' => '01a08bfa-6933-772b-b312-215837539f4a',
            'subject' => 'Đồng hồ Odo 36/8',
            'subject_ids' => ['852da54d-457a-4397-a16d-52d9452ba766'],
            'subject_scope_locked' => true,
            'capture_has_physical_assets' => true,
            'capture_owned_media_ids' => ['new-capture-media-36-8'],
            'allow_scoped_reuse' => true,
        ], ['featured_primary' => $historical->canonicalId, 'inline_primary' => $historical->canonicalId]);

        self::assertNotContains($historical->canonicalId, $result->slotMedia);
        self::assertNotContains($historical->canonicalId, array_map(static fn (MediaUsage $usage): string => $usage->mediaId, $usages->listByEndpoint('wp_post', '1:408')));
        self::assertSame('MEDIA_PLACEHOLDER', $result->state);
    }

    public function test_one_media_identity_can_fill_both_mandatory_article_roles(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $item = $service->create('one-image', 'One image', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/one.jpg', hash('sha256', 'one'), 'image/jpeg', 3, 1200, 800, 'PUBLIC');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(44, ['content_intent' => ['intent' => 'IMAGE_ARTICLE'], 'single_real_image_exception' => true], ['featured_primary' => $item->canonicalId, 'inline_primary' => $item->canonicalId]);

        self::assertSame($item->canonicalId, $result->slotMedia['featured_primary']);
        self::assertSame($item->canonicalId, $result->slotMedia['inline_primary']);
        self::assertFalse($result->slots['inline_primary']['placeholder']);
        self::assertNotContains('ARTICLE_MEDIA_INLINE_MISSING', array_column($result->diagnostics, 'code'));
    }

    public function test_capture_media_is_reconciled_into_article_slots_without_touching_representative_usages(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $item = $service->create('capture-media-a', 'Capture media A', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/capture-a.jpg', hash('sha256', 'capture-a-source'), 'image/jpeg', 8, 2400, 1600, 'PRIVATE');
        $service->addAsset($item->canonicalId, 'derivative', 'uploads/capture-a.webp', hash('sha256', 'capture-a-public'), 'image/webp', 4, 1200, 800, 'PUBLIC');
        $modelUsage = $service->addUsage($item->canonicalId, 'model', 'model-111', 'representative');
        $classificationUsage = $service->addUsage($item->canonicalId, 'classification', 'classification-cuckoo', 'representative');
        $dictionaryUsage = $service->addUsage($item->canonicalId, 'dictionary_concept', 'dictionary-clock', 'representative');
        $representativesBefore = $this->snapshotUsages([
            ...$usages->listByEndpoint('model', 'model-111'),
            ...$usages->listByEndpoint('classification', 'classification-cuckoo'),
            ...$usages->listByEndpoint('dictionary_concept', 'dictionary-clock'),
        ]);
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(573, [
            'capture_id' => 'capture-573',
            'content_intent' => ['intent' => 'IMAGE_ARTICLE'],
            'capture_owned_media_ids' => [$item->canonicalId],
            'single_real_image_exception' => true,
            'subject_ids' => ['subject-vedette-37'],
        ]);

        $articleUsages = array_values(array_filter(
            $usages->listByEndpoint('wp_post', '1:573'),
            static fn (MediaUsage $usage): bool => in_array($usage->role, ['featured_primary', 'inline_primary'], true),
        ));
        self::assertSame([$item->canonicalId], array_values(array_unique(array_map(static fn (MediaUsage $usage): string => $usage->mediaId, $articleUsages))));
        self::assertSame($modelUsage->usageId, $usages->listByEndpoint('model', 'model-111', 'representative')[0]->usageId);
        self::assertSame($classificationUsage->usageId, $usages->listByEndpoint('classification', 'classification-cuckoo', 'representative')[0]->usageId);
        self::assertSame($dictionaryUsage->usageId, $usages->listByEndpoint('dictionary_concept', 'dictionary-clock', 'representative')[0]->usageId);
        $representativesAfter = $this->snapshotUsages([
            ...$usages->listByEndpoint('model', 'model-111'),
            ...$usages->listByEndpoint('classification', 'classification-cuckoo'),
            ...$usages->listByEndpoint('dictionary_concept', 'dictionary-clock'),
        ]);
        self::assertSame($representativesBefore, $representativesAfter);
        self::assertSame('VERIFIED', $result->toArray()['canonical_readback']['media_usage']['state']);
        self::assertCount(2, $articleUsages);
    }

    public function test_capture_media_replay_keeps_media_and_article_usage_identities_without_new_assets(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $item = $service->create('capture-replay', 'Capture replay', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/capture-replay.jpg', hash('sha256', 'capture-replay-source'), 'image/jpeg', 8, 2400, 1600, 'PRIVATE');
        $service->addAsset($item->canonicalId, 'derivative', 'uploads/capture-replay.webp', hash('sha256', 'capture-replay-public'), 'image/webp', 4, 1200, 800, 'PUBLIC');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);
        $context = [
            'capture_id' => 'capture-replay',
            'content_intent' => ['intent' => 'IMAGE_ARTICLE'],
            'capture_owned_media_ids' => [$item->canonicalId],
            'single_real_image_exception' => true,
        ];

        $first = $coordinator->ensureForPost(574, $context);
        $firstMedia = $media->findByCanonicalId($item->canonicalId);
        $firstAssets = $this->snapshotAssets($assets->listByMediaId($item->canonicalId));
        $firstUsages = $this->snapshotUsages($usages->listByEndpoint('wp_post', '1:574'));
        $second = $coordinator->ensureForPost(574, $context);
        $secondMedia = $media->findByCanonicalId($item->canonicalId);
        $secondAssets = $this->snapshotAssets($assets->listByMediaId($item->canonicalId));
        $secondUsages = $this->snapshotUsages($usages->listByEndpoint('wp_post', '1:574'));

        self::assertSame([$item->canonicalId], array_values(array_unique($first->slotMedia)));
        self::assertSame($first->slotMedia, $second->slotMedia);
        self::assertNotNull($firstMedia);
        self::assertNotNull($secondMedia);
        self::assertSame($firstMedia->canonicalId, $secondMedia->canonicalId);
        self::assertSame($firstMedia->stableKey, $secondMedia->stableKey);
        self::assertSame($this->snapshotMedia($firstMedia), $this->snapshotMedia($secondMedia));
        self::assertSame($firstAssets, $secondAssets);
        self::assertSame($firstUsages, $secondUsages);
        self::assertSame(array_column($firstAssets, 'assetId'), array_column($secondAssets, 'assetId'));
        self::assertSame(array_column($firstUsages, 'usageId'), array_column($secondUsages, 'usageId'));
        self::assertCount(2, $assets->listByMediaId($item->canonicalId));
        self::assertCount(1, $media->items);
        self::assertCount(2, $usages->listByEndpoint('wp_post', '1:574'));
    }

    public function test_one_image_exception_is_only_for_one_ready_image_article_media(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $first = $service->create('capture-multi-a', 'Capture multi A', 'ready');
        $second = $service->create('capture-multi-b', 'Capture multi B', 'ready');
        foreach ([[$first, 'multi-a'], [$second, 'multi-b']] as [$item, $stem]) {
            $service->addAsset($item->canonicalId, 'original', 'uploads/' . $stem . '.jpg', hash('sha256', $stem . '-source'), 'image/jpeg', 8, 2400, 1600, 'PRIVATE');
            $service->addAsset($item->canonicalId, 'derivative', 'uploads/' . $stem . '.webp', hash('sha256', $stem . '-public'), 'image/webp', 4, 1200, 800, 'PUBLIC');
        }
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $multi = $coordinator->ensureForPost(575, [
            'capture_id' => 'capture-multi',
            'content_intent' => ['intent' => 'IMAGE_ARTICLE'],
            'capture_owned_media_ids' => [$first->canonicalId, $second->canonicalId],
            'single_real_image_exception' => true,
        ]);
        $text = $coordinator->ensureForPost(576, [
            'capture_id' => 'capture-text-with-image',
            'content_intent' => ['intent' => 'TEXT_ARTICLE'],
            'capture_owned_media_ids' => [$first->canonicalId],
            'single_real_image_exception' => true,
        ]);

        self::assertSame([$first->canonicalId, $second->canonicalId], array_values(array_unique($multi->slotMedia)));
        self::assertNotSame($multi->slotMedia['featured_primary'], $multi->slotMedia['inline_primary']);
        self::assertSame($first->canonicalId, $text->slotMedia['featured_primary']);
        self::assertTrue($text->slots['inline_primary']['placeholder']);
    }

    public function test_missing_capture_asset_is_typed_reconcile_blocker_and_not_satisfied_by_representative_usage(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $item = $service->create('capture-corrupt', 'Capture corrupt asset', 'ready');
        $service->addUsage($item->canonicalId, 'model', 'model-corrupt', 'representative');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(577, [
            'capture_id' => 'capture-corrupt',
            'content_intent' => ['intent' => 'IMAGE_ARTICLE'],
            'capture_owned_media_ids' => [$item->canonicalId],
            'single_real_image_exception' => true,
        ]);

        $readback = $result->toArray()['canonical_readback']['media_usage'];
        self::assertContains($readback['state'], ['RECONCILE', 'REVIEW_REQUIRED']);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $readback['blockers']);
        self::assertNotSame('VERIFIED', $readback['state']);
    }

    public function test_canonical_readback_rejects_usage_media_identity_mismatch_after_wordpress_synchronization(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $selected = $service->create('selected-after-sync', 'Selected after sync', 'ready');
        $service->addAsset($selected->canonicalId, 'derivative', 'uploads/selected-after-sync.webp', hash('sha256', 'selected-after-sync'), 'image/webp', 4, 1200, 800, 'PUBLIC');
        $wrong = $service->create('wrong-after-sync', 'Wrong after sync', 'ready');
        $service->addAsset($wrong->canonicalId, 'derivative', 'uploads/wrong-after-sync.webp', hash('sha256', 'wrong-after-sync'), 'image/webp', 4, 1200, 800, 'PUBLIC');
        $adapter = new class($usages, $wrong->canonicalId) implements WordPressArticleMediaAdapter {
            public function __construct(private object $usages, private string $wrongMediaId) {}
            public function read(int $postId): array { return ['featured_media_id' => null, 'inline_media_ids' => [], 'managed_inline_media_id' => null, 'featured_attachment_id' => 0, 'inline_attachment_ids' => [], 'content' => '']; }
            public function synchronize(int $postId, array $result): array
            {
                $rows = $this->usages->listByEndpoint('wp_post', '1:' . $postId, 'featured_primary');
                if ($rows !== []) {
                    $usage = $rows[0];
                    $this->usages->items[$usage->usageId] = new MediaUsage($usage->usageId, $this->wrongMediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder, $usage->altText, $usage->caption, $usage->keywordGroups, $usage->title, $usage->revision, $usage->placementKey);
                }
                return $this->read($postId);
            }
            public function attachmentForMedia(Media $media, MediaAsset $asset, string $contextualAlt = '', array $context = []): array { return []; }
            public function adoptAttachment(int $attachmentId): ?string { return null; }
        };
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1, $adapter);

        $result = $coordinator->ensureForPost(601, [
            'content_intent' => ['intent' => 'IMAGE_ARTICLE'],
            'capture_owned_media_ids' => [$selected->canonicalId],
            'single_real_image_exception' => true,
        ]);
        $readback = $result->toArray()['canonical_readback']['media_usage'];

        self::assertSame('REVIEW_REQUIRED', $readback['state']);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $readback['blockers']);
        self::assertContains('ARTICLE_MEDIA_FEATURED_MISSING', $readback['blockers']);
        self::assertNotContains('ARTICLE_MEDIA_ASSET_UNAVAILABLE', array_column($result->diagnostics, 'code'));
    }

    public function test_unready_explicit_single_image_selection_does_not_collapse_both_article_slots(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $unready = $service->create('selected-without-public-asset', 'Selected without public asset', 'ready');
        $fallback = $service->create('eligible-fallback', 'Eligible fallback', 'ready');
        $service->addAsset($fallback->canonicalId, 'derivative', 'uploads/eligible-fallback.webp', hash('sha256', 'eligible-fallback'), 'image/webp', 4, 1200, 800, 'PUBLIC');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(602, [
            'content_intent' => ['intent' => 'IMAGE_ARTICLE'],
            'single_real_image_exception' => true,
        ], [
            'featured_primary' => $unready->canonicalId,
            'inline_primary' => $unready->canonicalId,
        ]);

        self::assertNotSame($result->slotMedia['featured_primary'], $result->slotMedia['inline_primary']);
        self::assertNotContains($unready->canonicalId, $result->slotMedia);
        self::assertSame(1, count(array_filter($result->slots, static fn (array $slot): bool => $slot['placeholder'] === true)));
        self::assertContains($fallback->canonicalId, $result->slotMedia);
    }

    public function test_repeated_supporting_media_requires_explicit_unique_placements_and_converges_without_binary_duplication(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $item = $service->create('repeated-supporting', 'Repeated supporting image', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/repeated.webp', hash('sha256', 'repeated'), 'image/webp', 3, 1200, 800, 'PUBLIC');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $result = $coordinator->ensureForPost(46, [], [], [
            ['media_id' => $item->canonicalId, 'placement_key' => 'paragraph-a', 'sort_order' => 3],
            ['media_id' => $item->canonicalId, 'placement_key' => 'paragraph-b', 'sort_order' => 1],
        ]);
        $supporting = $usages->listByEndpoint('wp_post', '1:46', 'inline_supporting');

        usort($supporting, static fn (MediaUsage $left, MediaUsage $right): int => $left->sortOrder <=> $right->sortOrder);
        self::assertCount(2, $supporting);
        self::assertSame(['paragraph-b', 'paragraph-a'], array_column($supporting, 'placementKey'));
        self::assertNotSame($supporting[0]->placementAnchor(), $supporting[1]->placementAnchor());
        self::assertSame('MEDIA_COMPLETE', $result->state);
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

    public function test_managed_image_filename_is_always_contextual_ascii_webp_and_not_camera_name(): void
    {
        $filename = (new MediaFilenameNormalizer())->normalizeWebp('Máy ảnh Odo 36/8', 'image', 'IMG_1234.JPG', 'a71c');
        self::assertSame('may-anh-odo-36-8-a71c.webp', $filename);
        self::assertStringNotContainsString('IMG_1234', $filename);
        self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]*\.webp$/', $filename);
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
        $service->addAsset($real->canonicalId, 'original', 'uploads/replacement.jpg', hash('sha256', 'replacement'), 'image/jpeg', 11, 1200, 675, 'PUBLIC');

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
        $service->addAsset($real->canonicalId, 'original', 'uploads/public-featured.jpg', hash('sha256', 'public-featured'), 'image/jpeg', 14, 1200, 675, 'PUBLIC');
        $coordinator->ensureForPost(48, [], ['featured_primary' => $real->canonicalId]);
        self::assertTrue($projection->isImageSitemapEligible('1:48'));
    }

    public function test_coordinator_synchronizes_canonical_slots_to_wordpress_editorial_state(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $featured = $service->create('bridge-featured', 'Bridge featured', 'ready');
        $inline = $service->create('bridge-inline', 'Bridge inline', 'ready');
        $service->addAsset($featured->canonicalId, 'original', 'uploads/bridge-featured.jpg', hash('sha256', 'bridge-featured'), 'image/jpeg', 10, 1200, 675, 'PUBLIC');
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
        $asset = $service->addAsset($item->canonicalId, 'original', 'uploads/seo-bridge.jpg', hash('sha256', 'seo-bridge'), 'image/jpeg', 10, 1200, 675, 'PUBLIC');
        $service->addUsage($item->canonicalId, 'wp_post', '1:50', 'featured_primary', 0, 'Ảnh mặt trước', '', [], '', 'article:1:50:featured_primary');
        $adapter = new class implements WordPressArticleMediaAdapter {
            public function read(int $postId): array { return ['featured_media_id' => null, 'inline_media_ids' => [], 'managed_inline_media_id' => null, 'featured_attachment_id' => 0, 'inline_attachment_ids' => [], 'content' => '']; }
            public function synchronize(int $postId, array $result): array { return $this->read($postId); }
            public function attachmentForMedia(Media $media, MediaAsset $asset, string $contextualAlt = '', array $context = []): array { return ['url' => 'https://cdn.example.test/seo-bridge.jpg', 'src' => 'https://cdn.example.test/seo-bridge.jpg', 'srcset' => 'https://cdn.example.test/seo-bridge.jpg 1200w', 'sizes' => '100vw', 'width' => 1200, 'height' => 675, 'alt' => $contextualAlt, 'attachment_id' => 901]; }
            public function adoptAttachment(int $attachmentId): ?string { return null; }
        };

        $projection = new ArticleMediaSeoProjection($media, $assets, $usages, $adapter);
        $result = $projection->forPost('1:50');

        self::assertTrue($result['eligible']);
        self::assertSame('/anh/seo-bridge.webp', $result['image_url']);
        self::assertSame('100vw', $result['sizes']);
        self::assertSame('Ảnh mặt trước', $result['alt']);
    }

    public function test_article_seo_projection_resolves_valid_portrait_at_original_dimensions(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $item = $service->create('portrait-featured', 'Portrait featured', 'ready');
        $service->addAsset($item->canonicalId, 'original', 'uploads/portrait-featured.webp', hash('sha256', 'portrait-featured'), 'image/webp', 10, 900, 1200, 'PUBLIC');
        $service->addUsage($item->canonicalId, 'wp_post', '1:51', 'featured_primary', 0, 'Ảnh dọc', '', [], '', 'article:1:51:featured_primary');

        $result = (new ArticleMediaSeoProjection($media, $assets, $usages))->forPost('1:51');

        self::assertTrue($result['eligible']);
        self::assertSame(900, $result['width']);
        self::assertSame(1200, $result['height']);
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

    public function test_representative_evidence_and_technical_detail_roles_are_distinct_and_evidence_does_not_replace_representative(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $representative = $service->create('entity-representative', 'Entity representative', 'ready');
        $evidence = $service->create('entity-serial', 'Entity serial detail', 'ready');
        $service->addAsset($representative->canonicalId, 'original', 'uploads/entity-front.jpg', hash('sha256', 'entity-front'), 'image/jpeg', 10, 1200, 675, 'PUBLIC');
        $service->addAsset($evidence->canonicalId, 'original', 'uploads/entity-serial.jpg', hash('sha256', 'entity-serial'), 'image/jpeg', 10, 1200, 800, 'PUBLIC');

        $service->addUsage($representative->canonicalId, 'variant', '95873bfe-d978-4eda-a5a2-ce9ba79625df', 'representative', 0, 'Ảnh đại diện biến thể 36/10');
        $service->addUsage($evidence->canonicalId, 'variant', '95873bfe-d978-4eda-a5a2-ce9ba79625df', 'evidence', 0, 'Ảnh số serial biến thể 36/10');

        self::assertSame($representative->canonicalId, $usages->listByEndpoint('variant', '95873bfe-d978-4eda-a5a2-ce9ba79625df', 'representative')[0]->mediaId);
        self::assertSame($evidence->canonicalId, $usages->listByEndpoint('variant', '95873bfe-d978-4eda-a5a2-ce9ba79625df', 'evidence')[0]->mediaId);
    }

    public function test_representative_candidate_tie_uses_stable_key_not_insertion_or_upload_recency(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $later = $service->create('representative-z', 'Same subject', 'ready');
        $first = $service->create('representative-a', 'Same subject', 'ready');
        foreach ([[$later, 'later'], [$first, 'first']] as [$item, $suffix]) {
            $service->addAsset($item->canonicalId, 'original', 'uploads/' . $suffix . '.jpg', hash('sha256', $suffix), 'image/jpeg', 10, 1200, 675, 'PUBLIC');
        }

        $result = (new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1))->ensureForPost(901, ['subject' => 'Same subject']);

        self::assertSame($first->canonicalId, $result->slotMedia['featured_primary']);
    }

    public function test_source_original_and_derivative_replay_under_one_media_identity(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $source = ['kind' => 'original', 'storage_key' => 'uploads/odo-36-10-original.jpg', 'original_filename' => 'DSCF8291.JPG', 'checksum' => hash('sha256', 'source'), 'mime_type' => 'image/jpeg', 'byte_size' => 6, 'width' => 4000, 'height' => 3000, 'visibility' => 'PRIVATE', 'metadata' => ['source_original' => true]];
        $derivative = ['kind' => 'derivative', 'storage_key' => 'uploads/odo-36-10.webp', 'original_filename' => 'odo-36-10.webp', 'checksum' => hash('sha256', 'derivative'), 'mime_type' => 'image/webp', 'byte_size' => 4, 'width' => 1200, 'height' => 900, 'visibility' => 'PUBLIC', 'metadata' => ['derived_from' => 'odo-36-10-original.jpg']];

        $first = $service->ingest('upload:odo-36-10:' . hash('sha256', 'source'), 'Odo 36/10', 'ready', ['source' => 'multipart'], [$source, $derivative]);
        $second = $service->ingest('upload:odo-36-10:' . hash('sha256', 'source'), 'Odo 36/10', 'ready', ['source' => 'multipart'], [$source, $derivative]);

        self::assertSame($first->canonicalId, $second->canonicalId);
        self::assertCount(2, $assets->listByMediaId($first->canonicalId));
        self::assertSame(['original', 'derivative'], array_column($assets->listByMediaId($first->canonicalId), 'kind'));
    }

    public function test_existing_media_ingest_adds_usage_without_duplicate_create_or_media_identity(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $existing = $service->create('wp-attachment:1:299', 'Ảnh attachment 299', 'ready', ['source' => 'wordpress_attachment_adoption']);
        $service->addAsset($existing->canonicalId, 'original', 'uploads/attachment-299.webp', hash('sha256', 'attachment-299'), 'image/webp', 100, 1200, 900, 'PUBLIC');

        $reused = $service->ingest('wp-attachment:1:299', 'Tên đọc lại khác', 'draft', ['source' => 'mcp-replay'], [], [[
            'endpoint_type' => 'wp_post', 'endpoint_key' => '1:300', 'role' => 'featured_primary', 'alt_text' => 'Ảnh tư liệu attachment 299',
        ]]);

        self::assertSame($existing->canonicalId, $reused->canonicalId);
        self::assertCount(1, $media->items);
        self::assertCount(1, $usages->listByEndpoint('wp_post', '1:300', 'featured_primary'));
    }

    public function test_usage_replacement_updates_existing_usage_in_place_and_is_idempotent(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $old = $service->create('old-inline', 'Ảnh inline cũ', 'ready');
        $new = $service->create('new-featured', 'Ảnh hiện hành', 'ready');
        $service->addAsset($old->canonicalId, 'original', 'uploads/old.jpg', hash('sha256', 'old'), 'image/jpeg', 10, 1200, 800, 'PUBLIC');
        $service->addAsset($new->canonicalId, 'original', 'uploads/new.jpg', hash('sha256', 'new'), 'image/jpeg', 10, 1200, 675, 'PUBLIC');
        $existingUsage = $service->addUsage($old->canonicalId, 'wp_post', '1:300', 'inline_primary', 0, 'Alt cũ');
        $coordinator = new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1);

        $coordinator->ensureForPost(300, [], ['inline_primary' => $new->canonicalId, 'featured_primary' => $new->canonicalId]);
        $coordinator->ensureForPost(300, [], ['inline_primary' => $new->canonicalId, 'featured_primary' => $new->canonicalId]);
        $updated = $usages->listByEndpoint('wp_post', '1:300', 'inline_primary');

        self::assertCount(1, $updated);
        self::assertSame($existingUsage->usageId, $updated[0]->usageId);
        self::assertSame($new->canonicalId, $updated[0]->mediaId);
        self::assertSame(2, count($usages->items));
    }

    public function test_usage_reconciliation_refreshes_after_one_stale_revision_and_keeps_the_usage_identity(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->stores();
        $old = $service->create('stale-old', 'Ảnh inline cũ', 'ready');
        $new = $service->create('stale-new', 'Ảnh inline mới', 'ready');
        foreach ([[$old, 'stale-old'], [$new, 'stale-new']] as [$item, $stem]) {
            $service->addAsset($item->canonicalId, 'original', 'uploads/' . $stem . '.jpg', hash('sha256', $stem), 'image/jpeg', 10, 1200, 800, 'PUBLIC');
        }
        $existing = $service->addUsage($old->canonicalId, 'wp_post', '1:301', 'inline_primary', 0, 'Alt cũ');
        $usages->conflicts = 1;

        $result = (new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1))->ensureForPost(301, [], [
            'inline_primary' => $new->canonicalId,
            'featured_primary' => $new->canonicalId,
        ]);
        $inline = $usages->listByEndpoint('wp_post', '1:301', 'inline_primary');

        self::assertSame($new->canonicalId, $result->slotMedia['inline_primary']);
        self::assertCount(1, $inline);
        self::assertSame($existing->usageId, $inline[0]->usageId);
        self::assertSame(3, $inline[0]->revision);
        self::assertSame(2, $usages->updates);
    }

    /** @param list<MediaUsage> $usages @return list<array<string,mixed>> */
    private function snapshotUsages(array $usages): array
    {
        $snapshot = array_map(static fn (MediaUsage $usage): array => get_object_vars($usage), $usages);
        usort($snapshot, static function (array $left, array $right): int {
            return strcmp(
                implode("\0", [(string) ($left['endpointType'] ?? ''), (string) ($left['endpointKey'] ?? ''), (string) ($left['role'] ?? ''), (string) ($left['placementKey'] ?? ''), (string) ($left['usageId'] ?? '')]),
                implode("\0", [(string) ($right['endpointType'] ?? ''), (string) ($right['endpointKey'] ?? ''), (string) ($right['role'] ?? ''), (string) ($right['placementKey'] ?? ''), (string) ($right['usageId'] ?? '')]),
            );
        });
        return $snapshot;
    }

    /** @param list<MediaAsset> $assets @return list<array<string,mixed>> */
    private function snapshotAssets(array $assets): array
    {
        $snapshot = array_map(static fn (MediaAsset $asset): array => get_object_vars($asset), $assets);
        usort($snapshot, static fn (array $left, array $right): int => strcmp((string) ($left['assetId'] ?? ''), (string) ($right['assetId'] ?? '')));
        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function snapshotMedia(Media $media): array
    {
        return get_object_vars($media);
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
        $usages = new class implements MutableMediaUsageRepository, MediaUsageUpdater {
            public array $items = [];
            public int $conflicts = 0;
            public int $updates = 0;
            public function create(MediaUsage $usage): MediaUsage { return $this->items[$usage->usageId] = $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->mediaId === $id && ($role === null || $usage->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $type && $usage->endpointKey === $key && ($role === null || $usage->role === $role))); }
            public function update(MediaUsage $usage): MediaUsage
            {
                ++$this->updates;
                $current = $this->items[$usage->usageId] ?? null;
                if ($this->conflicts > 0) {
                    --$this->conflicts;
                    if ($current instanceof MediaUsage) $this->items[$usage->usageId] = new MediaUsage($current->usageId, $current->mediaId, $current->endpointType, $current->endpointKey, $current->role, $current->sortOrder, $current->altText . ' refreshed', $current->caption, $current->keywordGroups, $current->title, $current->revision + 1, $current->placementKey);
                    throw new MediaException('Media usage update conflict.');
                }
                $next = $usage->revision + 1;
                return $this->items[$usage->usageId] = new MediaUsage($usage->usageId, $usage->mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder, $usage->altText, $usage->caption, $usage->keywordGroups, $usage->title, $next, $usage->placementKey);
            }
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
