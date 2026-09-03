<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\{
    VideoCompletenessPolicy,
    VideoChapterParser,
    VideoEditorialGenerator,
    VideoHubClassifier,
    VideoIntakeService,
    VideoInternalSemanticResearcher,
    VideoRelationCandidatePlanner,
    VideoSeoProjection,
    VideoSitemapProjection,
    VideoSyncService,
    YouTubeDataApiClient,
    YouTubeSourceAdapter,
    YouTubeUrlNormalizer
};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Domain\Video\{
    TranscriptPolicy,
    Video,
    VideoSourceRights,
    YouTubeSourceSnapshot
};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class VideoSemanticCoreTest extends TestCase
{
    public function test_supported_youtube_forms_share_one_external_identity_and_ignore_tracking(): void
    {
        $urls = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&utm_source=nhk',
            'https://youtu.be/dQw4w9WgXcQ?si=tracking',
            'https://youtube.com/shorts/dQw4w9WgXcQ?feature=share',
            'https://youtube.com/embed/dQw4w9WgXcQ?start=30',
            'https://www.youtube.com/watch?list=PL-demo&v=dQw4w9WgXcQ',
            'https://youtube.com/playlist?list=PL-demo&v=dQw4w9WgXcQ',
        ];

        $identities = array_map(static fn (string $url): object => YouTubeUrlNormalizer::normalize($url), $urls);

        self::assertSame(['youtube'], array_values(array_unique(array_map(static fn (object $identity): string => $identity->platform, $identities))));
        self::assertSame(['dQw4w9WgXcQ'], array_values(array_unique(array_map(static fn (object $identity): string => $identity->videoId, $identities))));
        self::assertSame(['https://www.youtube.com/watch?v=dQw4w9WgXcQ'], array_values(array_unique(array_map(static fn (object $identity): string => $identity->canonicalUrl, $identities))));
    }

    public function test_youtube_adapter_returns_bounded_snapshot_without_fabricating_transcript(): void
    {
        $adapter = new YouTubeSourceAdapter(static fn (object $identity): array => [
            'channel_id' => 'UCnhk',
            'channel_title' => 'NHK Archive',
            'title' => 'Odo 36/8',
            'description' => 'Một mô tả nguồn.',
            'published_at' => '2026-09-02T00:00:00Z',
            'duration_seconds' => 420,
            'thumbnails' => ['https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'],
            'tags' => ['Odo 36/8'],
            'default_language' => 'vi',
            'caption_availability' => 'available',
            'embeddable' => true,
            'availability' => 'available',
            'live_state' => 'none',
            'fetched_at' => '2026-09-02T01:00:00Z',
        ]);

        $resolved = $adapter->resolve('https://youtu.be/dQw4w9WgXcQ');

        self::assertSame('youtube', $resolved->snapshot->platform);
        self::assertSame('dQw4w9WgXcQ', $resolved->snapshot->externalVideoId);
        self::assertSame('Odo 36/8', $resolved->snapshot->sourceTitle);
        self::assertSame(420, $resolved->snapshot->durationSeconds);
        self::assertSame('available', $resolved->snapshot->availability);
        self::assertSame('NO_TRANSCRIPT', TranscriptPolicy::none()->kind);
        self::assertArrayNotHasKey('transcript', $resolved->toArray());
    }

    public function test_youtube_api_client_classifies_rate_limit_without_exposing_api_key(): void
    {
        $client = new YouTubeDataApiClient('secret-key', static fn (string $url, array $options): array => ['response' => ['code' => 429], 'body' => '{}']);

        try {
            $client->fetch(YouTubeUrlNormalizer::normalize('https://youtu.be/dQw4w9WgXcQ'));
            self::fail('Expected API rate limit.');
        } catch (\NHK\Core\Domain\Video\VideoException $error) {
            self::assertSame('API_RATE_LIMIT', $error->getMessage());
            self::assertStringNotContainsString('secret-key', $error->getMessage());
        }
    }

    public function test_snapshot_and_rights_use_controlled_values_and_stable_hash(): void
    {
        $snapshot = YouTubeSourceSnapshot::fromArray([
            'platform' => 'youtube',
            'external_video_id' => 'dQw4w9WgXcQ',
            'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'source_title' => 'Reference',
            'availability' => 'available',
            'embeddable' => true,
            'fetched_at' => '2026-09-02T01:00:00Z',
        ]);

        self::assertSame($snapshot->hash(), YouTubeSourceSnapshot::fromArray($snapshot->toArray())->hash());
        self::assertSame('UNKNOWN', VideoSourceRights::UNKNOWN);
        self::assertSame('PUBLIC_EXTERNAL_REFERENCE', VideoSourceRights::PUBLIC_EXTERNAL_REFERENCE);
    }

    public function test_snapshot_preserves_unknown_embeddability_instead_of_claiming_false(): void
    {
        $snapshot = YouTubeSourceSnapshot::fromArray([
            'platform' => 'youtube',
            'external_video_id' => 'dQw4w9WgXcQ',
            'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'availability' => 'unknown',
            'embeddable' => null,
        ]);

        self::assertNull($snapshot->embeddable);
    }

    public function test_relation_planner_requires_registered_predicate_canonical_target_and_evidence(): void
    {
        $planner = new VideoRelationCandidatePlanner(new PredicateRegistry());
        $videoId = '11111111-1111-4111-8111-111111111111';
        $targetId = '22222222-2222-4222-8222-222222222222';

        $candidates = $planner->plan($videoId, [[
            'target_id' => $targetId,
            'target_type' => 'brand',
            'predicate' => 'about',
            'origin' => 'EXPLICIT_USER_RELATION',
            'evidence_refs' => [['kind' => 'USER_HINT', 'locator' => 'hint']],
            'reason' => 'User supplied Odo as context.',
            'confidence' => 1.0,
        ]]);

        self::assertSame($targetId, $candidates[0]->targetId);
        self::assertSame('about', $candidates[0]->predicate);
        self::assertSame('video', $candidates[0]->sourceType);
        self::assertSame($videoId, $candidates[0]->sourceKey);

        $this->expectException(\InvalidArgumentException::class);
        $planner->plan($videoId, [[
            'target_id' => $targetId,
            'target_type' => 'brand',
            'predicate' => 'invented_predicate',
            'origin' => 'INFERRED_RELATION',
            'evidence_refs' => [],
        ]]);
    }

    public function test_classifier_uses_multi_signal_evidence_and_returns_one_primary_hub(): void
    {
        $classified = (new VideoHubClassifier())->classify([
            'source_title' => 'Nghe âm thanh đồng hồ cổ',
            'source_description' => 'Phân tích tiếng chuông và cơ cấu phát âm.',
            'tags' => ['đồng hồ cổ', 'âm thanh'],
            'user_hint' => 'Tập trung vào chất âm.',
        ]);

        self::assertSame('06', $classified['primary']['key']);
        self::assertSame('Âm thanh đồng hồ cổ', $classified['primary']['label']);
        self::assertNotEmpty($classified['evidence']);
        self::assertCount(1, array_filter($classified['categories'], static fn (array $item): bool => $item['primary'] === true));
    }

    public function test_editorial_package_is_synthesis_and_completeness_blocks_orphan_and_missing_seo(): void
    {
        $editorial = (new VideoEditorialGenerator())->generate(
            ['source_title' => 'Odo 36/8', 'source_description' => 'Mô tả gốc không được chép lại.'],
            'Video nói về Odo 36/8 và cách nhận diện máy.',
            'Hướng dẫn nhập môn.',
        );

        self::assertNotSame('Mô tả gốc không được chép lại.', $editorial['body']);
        self::assertSame('USER_HINT', $editorial['context'][0]['provenance']);

        $result = (new VideoCompletenessPolicy())->evaluate([
            'source' => ['identity_valid' => true, 'availability' => 'available'],
            'editorial' => $editorial,
            'category' => ['primary' => ['key' => '06']],
            'semantic_attachments' => [],
            'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'seo' => ['title' => 'Odo 36/8 — Âm thanh đồng hồ cổ', 'description' => 'Mô tả SEO hữu ích.'],
        ]);

        self::assertFalse($result->publishable);
        self::assertContains('NO_SEMANTIC_ATTACHMENT', $result->blockers);
    }

    public function test_seo_projection_maps_only_canonical_visible_data_and_video_object(): void
    {
        $projection = (new VideoSeoProjection())->project([
            'source' => ['external_video_id' => 'dQw4w9WgXcQ', 'published_at' => '2026-09-02T00:00:00Z', 'duration_seconds' => 420, 'thumbnail_urls' => ['https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg']],
            'editorial' => ['title' => 'Odo 36/8 — Âm thanh đồng hồ cổ', 'summary' => 'Tìm hiểu âm thanh qua bối cảnh biên tập NHK.', 'body' => 'Nội dung độc lập hữu ích.'],
            'seo' => ['title' => 'Odo 36/8 — Âm thanh đồng hồ cổ', 'description' => 'Một mô tả SEO trung thực.'],
        ], 'https://nhk.example/video/odo-36-8-dqw4w9wxcq/');

        self::assertSame('VideoObject', $projection['video_object']['@type']);
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $projection['video_object']['embedUrl']);
        self::assertSame('PT7M', $projection['video_object']['duration']);
        self::assertSame('https://nhk.example/video/odo-36-8-dqw4w9wxcq/', $projection['canonical']);
        self::assertArrayNotHasKey('canonical_id', $projection['video_object']);
    }

    public function test_chapters_require_source_timestamps_and_carry_description_evidence(): void
    {
        $chapters = (new VideoChapterParser())->parse("00:00 Giới thiệu\n01:20 Nhận diện máy\n00:40 Không tăng dần\n99:00 Ngoài thời lượng", 180);

        self::assertSame([0, 80], array_column($chapters, 'start_seconds'));
        self::assertSame('YOUTUBE_DESCRIPTION', $chapters[1]['evidence']['kind']);
    }

    public function test_intake_resolves_existing_nhk_context_and_builds_one_governed_proposal_packet(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository();
        $brand = $authority->create(new AuthorityEntity('33333333-3333-4333-8333-333333333333', 'brand', 'brand:odo', 'Odo', 1, ['aliases' => ['Ô Đô']]));
        $videos = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $adapter = new YouTubeSourceAdapter(static fn (object $identity): array => ['title' => 'Odo 36/8', 'description' => 'Âm thanh và nhận diện bộ máy.', 'availability' => 'available', 'embeddable' => true, 'duration_seconds' => 420, 'fetched_at' => '2026-09-02T01:00:00Z']);
        $service = new VideoIntakeService($adapter, $videos, new VideoHubClassifier(), new VideoRelationCandidatePlanner(new PredicateRegistry()), new VideoEditorialGenerator(), new VideoCompletenessPolicy(), new VideoSeoProjection(), new VideoInternalSemanticResearcher($authority, $types));

        $preview = $service->preview('https://youtu.be/dQw4w9WgXcQ', 'Video này nói về Odo 36/8 và chất âm.');
        $proposal = $service->proposalArguments($preview, 'video-intake-test');

        self::assertSame('ingest', $preview->operation);
        self::assertSame($brand->canonicalId, $preview->package['semantic_attachments'][0]['target_key']);
        self::assertSame('EXPLICIT_USER_RELATION', $preview->package['semantic_attachments'][0]['origin']);
        self::assertSame('video', $proposal['entity_type']);
        self::assertSame('video-intake-test', $proposal['idempotency_key']);
        self::assertSame($preview->videoId, $proposal['payload']['canonical_id']);
        self::assertSame('Odo 36/8', $preview->package['source']['source_title']);
        self::assertTrue($preview->package['completeness']['publishable']);
    }

    public function test_sync_only_reports_source_change_and_never_overwrites_nhk_editorial(): void
    {
        $old = YouTubeSourceSnapshot::fromArray(['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'source_title' => 'Old source', 'availability' => 'available', 'fetched_at' => '2026-09-01T00:00:00Z']);
        $video = Video::fromUrl('https://youtu.be/dQw4w9WgXcQ', 'NHK editorial title', ['source_snapshot' => $old->toArray()]);
        $new = YouTubeSourceSnapshot::fromArray(['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'source_title' => 'New source', 'availability' => 'available', 'fetched_at' => '2026-09-02T00:00:00Z']);

        $result = (new VideoSyncService())->compare($video, $new);

        self::assertSame('SOURCE_CHANGED', $result->status);
        self::assertContains('source_title', $result->changedFields);
        self::assertTrue($result->reconciliationRequired);
        self::assertSame('NHK editorial title', $video->title);
    }

    public function test_video_sitemap_contains_only_active_available_indexable_watch_pages(): void
    {
        $valid = Video::fromUrl('https://youtu.be/dQw4w9WgXcQ', 'NHK title', ['source_snapshot' => ['availability' => 'available', 'thumbnail_urls' => ['https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg']], 'editorial' => ['title' => 'NHK title', 'summary' => 'Summary']]);
        $unavailable = Video::fromUrl('https://youtu.be/9bZkp7q19f0', 'Unavailable', ['source_snapshot' => ['availability' => 'deleted']]);
        $notIndexable = Video::fromUrl('https://youtu.be/aqz-KE-bpKQ', 'No index', ['source_snapshot' => ['availability' => 'available'], 'indexable' => false]);

        $items = (new VideoSitemapProjection())->project([$valid, $unavailable, $notIndexable], 'https://nhk.example');

        self::assertCount(1, $items);
        self::assertSame('https://nhk.example/video/nhk-title-dqw4w9wgxcq/', $items[0]['loc']);
        self::assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $items[0]['thumbnail_url']);
    }
}
