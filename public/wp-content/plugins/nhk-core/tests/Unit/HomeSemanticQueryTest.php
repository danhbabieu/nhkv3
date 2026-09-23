<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Home\HomeSemanticQuery;
use NHK\Core\Application\Presentation\PublicNavigationDefinition;
use NHK\Core\Application\Entity\{PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Application\Media\PublicMediaGalleryQuery;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Contracts\Home\BoundedLatestFeedReader;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Media\{Media, MediaAsset};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class HomeSemanticQueryTest extends TestCase
{
    public function test_home_semantic_modules_have_bounded_purpose_labels_and_hide_empty_discovery_sections(): void
    {
        $modules = (new HomeSemanticQuery(new InMemoryAuthorityRepository(), $this->media([]), $this->videos([]), new EntityTypeRegistry()))
            ->extend([]);

        self::assertSame(['entities', 'media', 'videos', 'knowledge', 'hubs', 'clock_groups', 'explore_next', 'latest_feed', 'clock_groups_total', 'media_total', 'videos_total'], array_keys($modules));
        self::assertSame([], $modules['entities']);
        self::assertSame([], $modules['explore_next']);
        self::assertArrayNotHasKey('odo', json_decode(json_encode($modules), true));
    }

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
        $video = new Video(UuidCodec::newV7(), 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Video kỹ thuật', ['public_identity' => ['current_slug' => 'video-ky-thuat'], 'source_snapshot' => ['availability' => 'available', 'embeddable' => true, 'thumbnail_selection' => ['url' => 'https://img.example.test/video.jpg', 'variant' => 'mqdefault', 'width' => 320, 'height' => 180]], 'editorial' => ['title' => 'Video kỹ thuật', 'summary' => 'Tóm tắt'], 'hub' => ['primary' => '06'], 'provenance' => ['kind' => 'TEST'], 'semantic_attachments' => [['target_type' => 'variant', 'target_uuid' => UuidCodec::newV7(), 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => UuidCodec::newV7()]]]]]);
        $mediaRepo = $this->media([$media]);
        $gallery = new PublicMediaGalleryQuery($mediaRepo, $this->assets([$asset]));

        $modules = (new HomeSemanticQuery(new InMemoryAuthorityRepository(), $mediaRepo, $this->videos([$video]), new EntityTypeRegistry(), null, null, null, $gallery))
            ->extend(['entities' => [], 'media' => [], 'videos' => []]);

        self::assertStringContainsString('/anh/front.webp', (string) ($modules['media'][0]['image_url'] ?? ''));
        self::assertArrayNotHasKey('url', $modules['media'][0]);
        self::assertSame($video->canonicalId, $modules['videos'][0]['canonical_id'] ?? null);
        self::assertSame('https://img.example.test/video.jpg', $modules['videos'][0]['thumbnail_url'] ?? null);
    }

    public function test_home_clock_groups_are_profile_driven_and_skip_incomplete_presentation_records(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository();
        $clockType = new AuthorityEntity(UuidCodec::newV7(), 'classification', 'nhk:classification:clock-type.public', 'Đồng hồ công cộng', 1, ['family' => 'clock_type', 'description' => 'Nhóm các đồng hồ phục vụ không gian công cộng.'], AuthorityState::ACTIVE, 1, '2026-01-01 00:00:00');
        $incomplete = new AuthorityEntity(UuidCodec::newV7(), 'classification', 'nhk:classification:clock-type.empty', 'Đồng hồ chưa đủ nội dung', 1, ['family' => 'clock_type'], AuthorityState::ACTIVE, 1, '2026-02-01 00:00:00');
        $authority->create($clockType);
        $authority->create($incomplete);
        $routes = new PublicRouteResolver($authority, $types);
        $identity = new class implements \NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository {
            public function allocate(array $record, string $idempotencyKey): array { return []; }
            public function change(array $record, string $oldPath, int $expectedRevision, string $idempotencyKey): array { return []; }
            public function findCurrentById(string $identityId): ?array { return null; }
            public function findCurrentByOwner(string $ownerKind, string $ownerId, string $routeType): ?array { return ['current_slug' => 'dong-ho-cong-cong']; }
            public function slugExists(string $routeType, string $scope, string $slug, ?string $excludeIdentityId = null): bool { return false; }
            public function resolveHistoric(string $path): array { return []; }
        };
        $collection = new PublicEntityCollectionQuery($authority, $types, new PublicIdentityContract($types, $identity), new PublicEntityEligibilityPolicy($authority, $types, $routes), $routes);
        $modules = (new HomeSemanticQuery($authority, $this->media([]), $this->videos([]), $types, null, $routes, $collection))->extend([]);

        self::assertSame(['Đồng hồ công cộng'], array_column($modules['clock_groups'], 'title'));
        self::assertSame('Nhóm đồng hồ', $modules['hubs'][0]['label']);
    }

    public function test_home_counts_only_media_with_a_usable_public_visual(): void
    {
        $media = new Media(UuidCodec::newV7(), 'no-asset', 'Không có ảnh', 'ready');
        $mediaRepo = $this->media([$media]);
        $gallery = new PublicMediaGalleryQuery($mediaRepo, $this->assets([]));

        $modules = (new HomeSemanticQuery(new InMemoryAuthorityRepository(), $mediaRepo, $this->videos([]), new EntityTypeRegistry(), null, null, null, $gallery))
            ->extend(['entities' => [], 'media' => [], 'videos' => []]);

        self::assertSame(0, $modules['media_total']);
        self::assertSame([], $modules['media']);
    }

    public function test_home_hub_order_follows_public_navigation_without_entity_specific_exceptions(): void
    {
        $ordered = PublicNavigationDefinition::sortHubItems([
            ['type' => 'model', 'label' => 'Mẫu', 'url' => '/mau/'],
            ['type' => 'clock_type', 'label' => 'Nhóm đồng hồ', 'url' => '/loai-dong-ho/'],
            ['type' => 'brand', 'label' => 'Thương hiệu', 'url' => '/thuong-hieu/'],
        ]);

        self::assertSame(['brand', 'clock_type', 'model'], array_column($ordered, 'type'));
    }

    public function test_home_video_preview_keeps_newest_first_while_counting_the_full_filtered_source(): void
    {
        $videos = [];
        for ($index = 0; $index < 7; $index++) {
            $externalId = 'abcde12345' . (string) $index;
            $videos[] = Video::fromUrl(
                'https://www.youtube.com/watch?v=' . $externalId,
                'Video ' . (string) $index,
                ['public_identity' => ['current_slug' => 'video-' . $index], 'source_snapshot' => ['availability' => 'available', 'embeddable' => true, 'published_at' => '2026-01-' . str_pad((string) (7 - $index), 2, '0', STR_PAD_LEFT) . ' 00:00:00'], 'editorial' => ['title' => 'Video ' . $index, 'summary' => 'Tóm tắt'], 'hub' => ['primary' => '06'], 'provenance' => ['kind' => 'TEST'], 'semantic_attachments' => [['target_type' => 'variant', 'target_uuid' => UuidCodec::newV7(), 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => UuidCodec::newV7()]]]]],
            );
        }

        $modules = (new HomeSemanticQuery(new InMemoryAuthorityRepository(), $this->media([]), $this->videos($videos), new EntityTypeRegistry()))
            ->extend(['entities' => [], 'media' => [], 'videos' => []]);

        self::assertSame(7, $modules['videos_total']);
        self::assertCount(6, $modules['videos']);
        self::assertSame('Video 0', $modules['videos'][0]['title']);
    }

    public function test_home_latest_feed_uses_owner_created_at_and_fails_closed_without_canonical_video_route(): void
    {
        $video = Video::fromUrl(
            'https://www.youtube.com/watch?v=abcde123456',
            'Video công khai',
            ['source_snapshot' => ['availability' => 'available', 'published_at' => '2026-02-01 00:00:00']],
            null,
            '11111111-1111-4111-8111-111111111111',
        );
        $media = new Media('22222222-2222-4222-8222-222222222222', 'latest-photo', 'Ảnh công khai', 'ready', [], true, 1, '2026-03-01 00:00:00');
        $modules = (new HomeSemanticQuery(new InMemoryAuthorityRepository(), $this->media([$media]), $this->videos([$video]), new EntityTypeRegistry()))
            ->extend(['entities' => [], 'media' => [], 'videos' => []]);

        self::assertSame(['media'], array_column($modules['latest_feed'], 'type'));
        self::assertSame('2026-03-01 00:00:00', $modules['latest_feed'][0]['created_at']);
        self::assertArrayHasKey('tie_breaker', $modules['latest_feed'][0]);
    }

    public function test_home_reuses_request_scope_source_and_media_projection_reads(): void
    {
        $media = new Media($mediaId = UuidCodec::newV7(), 'memo-photo', 'Ảnh memo', 'ready');
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'memo.webp', hash('sha256', 'memo'), 'image/webp', 1, 640, 480, 'PUBLIC', ['canonical_filename' => 'memo.webp']);
        $mediaRepo = new class([$media]) implements MediaRepository {
            public int $listCalls = 0;
            public int $findCalls = 0;
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?Media { $this->findCalls++; foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { $this->listCalls++; return $this->items; }
        };
        $videoRepo = new class([]) implements VideoRepository {
            public int $listCalls = 0;
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { $this->listCalls++; return []; }
        };
        $gallery = new PublicMediaGalleryQuery($mediaRepo, $this->assets([$asset]));

        (new HomeSemanticQuery(new InMemoryAuthorityRepository(), $mediaRepo, $videoRepo, new EntityTypeRegistry(), null, null, null, $gallery))
            ->extend(['entities' => [], 'media' => [], 'videos' => []]);

        self::assertSame(1, $mediaRepo->listCalls);
        self::assertSame(1, $mediaRepo->findCalls);
        self::assertSame(1, $videoRepo->listCalls);
    }

    public function test_latest_feed_reads_a_bounded_large_media_dataset_and_returns_global_top_twelve(): void
    {
        $items = [];
        for ($index = 0; $index < 100; $index++) {
            $items[] = new Media(UuidCodec::newV7(), 'latest-' . $index, 'Ảnh ' . $index, 'ready', [], true, 1, sprintf('2026-01-%03d 00:00:00', $index + 1));
        }
        $repository = new class($items) implements MediaRepository, BoundedLatestFeedReader {
            public int $boundedCalls = 0;
            public function __construct(private array $items) {}
            public function latestFeedCandidates(int $limit): array { $this->boundedCalls++; return array_slice(array_reverse($this->items), 0, $limit); }
            public function findByCanonicalId(string $id): ?Media { return null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { throw new \LogicException('latest feed must not use the unbounded list reader'); }
        };
        $query = new HomeSemanticQuery(new InMemoryAuthorityRepository(), $repository, $this->videos([]), new EntityTypeRegistry());
        $method = new \ReflectionMethod($query, 'latestFeed');
        $feed = $method->invoke($query, []);

        self::assertSame(1, $repository->boundedCalls);
        self::assertCount(HomeSemanticQuery::LATEST_VISIBLE_LIMIT, $feed);
        self::assertSame('Ảnh 99', $feed[0]['title']);
        self::assertSame('Ảnh 88', $feed[11]['title']);
    }

    public function test_latest_feed_merges_bounded_sources_public_only_with_deterministic_order_and_canonical_dedupe(): void
    {
        $sharedId = '33333333-3333-4333-8333-333333333333';
        $mediaItems = [];
        for ($index = 0; $index < 100; $index++) {
            $mediaItems[] = new Media(
                $index === 99 ? $sharedId : UuidCodec::newV7(),
                'mixed-media-' . $index,
                'Ảnh ' . $index,
                'ready',
                [],
                true,
                1,
                sprintf('2026-02-%02d 00:00:00', min(28, $index + 1)),
            );
        }
        $mediaItems[] = new Media(UuidCodec::newV7(), 'mixed-private-media', 'Ảnh private', 'draft', [], true, 1, '2026-12-31 00:00:00');

        $videoItems = [];
        for ($index = 0; $index < 30; $index++) {
            $videoItems[] = Video::fromUrl(
                'https://www.youtube.com/watch?v=' . str_pad((string) $index, 11, 'v', STR_PAD_LEFT),
                'Video ' . $index,
                ['source_snapshot' => [
                    'availability' => $index === 29 ? 'removed' : 'available',
                    'embeddable' => true,
                    'published_at' => sprintf('2026-02-%02d 00:00:00', min(28, $index + 1)),
                ], 'editorial' => ['title' => 'Video ' . $index, 'summary' => 'Tóm tắt'], 'hub' => ['primary' => ['key' => 'video']], 'provenance' => ['kind' => 'external'], 'semantic_attachments' => [['type' => 'authority']], 'public_identity' => ['current_slug' => 'video-' . $index]],
                null,
                $index === 28 ? $sharedId : null,
            );
        }

        $mediaRepository = new class($mediaItems) implements MediaRepository, BoundedLatestFeedReader {
            public int $boundedCalls = 0;
            public function __construct(private array $items) {}
            public function latestFeedCandidates(int $limit): array { $this->boundedCalls++; return array_slice($this->items, -$limit); }
            public function findByCanonicalId(string $id): ?Media { return null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { throw new \LogicException('latest feed must not use the unbounded Media reader'); }
        };
        $videoRepository = new class($videoItems) implements VideoRepository, BoundedLatestFeedReader {
            public int $boundedCalls = 0;
            public function __construct(private array $items) {}
            public function latestFeedCandidates(int $limit): array { $this->boundedCalls++; return array_slice($this->items, -$limit); }
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { throw new \LogicException('latest feed must not use the unbounded Video reader'); }
        };

        $query = new HomeSemanticQuery(new InMemoryAuthorityRepository(), $mediaRepository, $videoRepository, new EntityTypeRegistry());
        $feed = (new \ReflectionMethod($query, 'latestFeed'))->invoke($query, []);

        self::assertSame(1, $mediaRepository->boundedCalls);
        self::assertSame(1, $videoRepository->boundedCalls);
        self::assertCount(HomeSemanticQuery::LATEST_VISIBLE_LIMIT, $feed);
        self::assertSame('Video 28', $feed[0]['title']);
        self::assertNotContains('Video 29', array_column($feed, 'title'));
        self::assertNotContains('Ảnh private', array_column($feed, 'title'));
        self::assertCount(count(array_unique(array_column($feed, 'tie_breaker'))), $feed);
        self::assertNotContains($sharedId, array_slice(array_column($feed, 'tie_breaker'), 1));
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
