<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\AuthorityProposalExecutor;
use NHK\Core\Application\Video\{VideoEditorialGenerator, VideoEditorialResumePlanner, VideoSeoProjection, VideoService};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Video\Video;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class VideoEditorialResumePlannerTest extends TestCase
{
    public function test_video_resume_rebuilds_the_same_canonical_owner_from_current_editorial_inputs(): void
    {
        $videoId = '01a07971-2fe3-77da-9424-998cf6f249e0';
        $repository = new VideoEditorialResumeTestRepository(new Video(
            $videoId,
            'youtube',
            'dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'Nguồn video gốc',
            [
                'source' => ['external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'source_title' => 'Nguồn video gốc'],
                'editorial' => ['title' => 'OLD TITLE', 'summary' => 'OLD SUMMARY', 'body' => 'OLD BODY', 'why_this_matters' => 'OLD WHY'],
                'seo' => ['title' => 'OLD SEO', 'description' => 'OLD SEO DESCRIPTION'],
                'semantic_attachments' => [],
            ],
            null,
            true,
            2,
        ));
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection());

        $plan = $planner->plan([
            'operation' => 'ingest',
            'entity_type' => 'video',
            'payload' => ['canonical_id' => $videoId],
        ], [
            'continuation_delta_text' => 'Giải thích vì sao bộ máy này đáng chú ý với người sưu tầm.',
            'subject_resolution' => ['primary' => ['id' => '22222222-2222-4222-8222-222222222222', 'type' => 'variant', 'name' => 'Odo 36/8']],
            'retrieval' => ['selected_claims' => [['id' => 'claim-1', 'revision' => 4, 'text' => 'Claim hiện hành.']]],
        ]);

        self::assertSame('REBUILD_EDITORIAL', $plan['status']);
        self::assertSame('update', $plan['operation']);
        self::assertSame($videoId, $plan['target_uuid']);
        self::assertSame(2, $plan['expected_revision']);
        self::assertNotSame('OLD SUMMARY', $plan['payload']['metadata']['editorial']['summary']);
        self::assertSame($plan['payload']['metadata']['editorial']['summary'], $plan['payload']['metadata']['seo']['description']);
        self::assertSame($plan['payload']['metadata']['editorial']['summary'], $plan['payload']['metadata']['seo_projection']['description']);
        self::assertSame($plan['payload']['metadata']['seo_projection']['description'], $plan['payload']['metadata']['seo_projection']['open_graph']['description']);
        self::assertSame($plan['payload']['metadata']['seo_projection']['description'], $plan['payload']['metadata']['seo_projection']['video_object']['description']);

        $updated = (new AuthorityProposalExecutor(
            new AuthorityService(new InMemoryAuthorityRepository(), new EntityTypeRegistry()),
            null,
            null,
            new VideoService($repository),
        ))(new Proposal(
            'video-editorial-resume',
            $videoId,
            'update',
            $plan['payload'],
            'content',
            2,
            'dependencies',
            ProposalState::APPROVED,
            idempotencyKey: 'video-editorial-resume',
            targetUuid: $videoId,
            entityType: 'video',
        ));

        self::assertSame($videoId, $updated->canonicalId);
        self::assertSame($videoId, $repository->findByCanonicalId($videoId)?->canonicalId);
        self::assertSame($plan['payload']['metadata']['editorial'], $repository->findByCanonicalId($videoId)?->metadata['editorial']);
        self::assertSame($plan['payload']['metadata']['editorial_input_fingerprint'], $repository->findByCanonicalId($videoId)?->metadata['editorial_input_fingerprint']);
    }

    public function test_video_resume_reenters_governed_ingest_when_capture_only_has_planned_identity(): void
    {
        $videoId = '01a0aaf8-2a84-7287-bbd8-70af4d5485e4';
        $repository = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection(), null, static function (array $context): array {
            $subject = $context['intended_targets'][0] ?? [];
            return ['status' => 'available', 'subject' => $subject, 'candidates' => [], 'diagnostics' => [], 'proposal_ready' => false, 'unresolved_reasons' => []];
        });

        $resumeContext = $this->resumeContext();
        $resumeContext['subject_resolution'] = ['primary' => ['id' => '01a09e44-539a-7f1a-938a-d7d91bb689a3', 'type' => 'classification']];
        $plan = $planner->plan(['operation' => 'ingest', 'entity_type' => 'video', 'idempotency_key' => 'capture:retry:video', 'payload' => [
            'canonical_id' => $videoId,
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']],
        ]], $resumeContext + ['capture_id' => 'capture-retry']);

        self::assertSame('REBUILD_INGEST', $plan['status']);
        self::assertSame('ingest', $plan['operation']);
        self::assertSame($videoId, $plan['subject_id']);
        self::assertNull($plan['target_uuid']);
        self::assertNull($plan['expected_revision']);
        self::assertSame('capture:retry:video', $plan['idempotency_key']);
        self::assertSame($videoId, $plan['payload']['canonical_id']);
        self::assertSame('dQw4w9WgXcQ', $plan['payload']['metadata']['source']['external_video_id']);
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $plan['payload']['metadata']['embed_url']);
        self::assertSame('01a09e44-539a-7f1a-938a-d7d91bb689a3', $plan['payload']['metadata']['knowledge_enrichment']['subject']['id']);
        self::assertSame('classification', $plan['payload']['metadata']['knowledge_enrichment']['subject']['type']);
        self::assertNotContains('NO_SUPPORTED_SUBJECT', $plan['payload']['metadata']['knowledge_enrichment']['diagnostics']);
    }

    public function test_rebuilt_embed_survives_controlled_apply_and_reuses_the_same_owner(): void
    {
        $videoId = '01a0aaf8-2a84-7287-bbd8-70af4d5485e4';
        $repository = new class implements VideoRepository {
            /** @var array<string,Video> */
            public array $items = [];
            public function findByCanonicalId(string $id): ?Video { return $this->items[$id] ?? null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { foreach ($this->items as $video) if ($video->platform === $platform && $video->externalVideoId === $externalId) return $video; return null; }
            public function create(Video $video): Video { return $this->items[$video->canonicalId] = $video; }
            public function update(Video $video, int $expectedRevision): Video { return $this->items[$video->canonicalId] = $video; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection());
        $plan = $planner->plan(['operation' => 'ingest', 'entity_type' => 'video', 'idempotency_key' => 'capture:retry:video', 'payload' => [
            'canonical_id' => $videoId,
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'availability' => 'available', 'embeddable' => true], 'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE'],
        ]], [
            'continuation_delta_text' => '',
            'subject_resolution' => ['primary' => ['id' => '01a09e44-539a-7f1a-938a-d7d91bb689a3', 'type' => 'classification']],
            'retrieval' => ['selected_claims' => []],
        ]);
        $proposal = new Proposal('video-rebuilt-apply', $videoId, 'ingest', $plan['payload'], 'content', 1, 'dependencies', ProposalState::APPROVED, idempotencyKey: 'capture:retry:video', entityType: 'video');
        $executor = new AuthorityProposalExecutor(new AuthorityService(new InMemoryAuthorityRepository(), new EntityTypeRegistry()), null, null, new VideoService($repository));

        $first = $executor($proposal);
        $second = $executor($proposal);

        self::assertSame($videoId, $first->canonicalId);
        self::assertSame($videoId, $second->canonicalId);
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $repository->findByCanonicalId($videoId)?->metadata['embed_url']);
        self::assertCount(1, $repository->list());
    }

    public function test_legacy_preview_derived_state_is_not_authoritative_for_absent_video_retry(): void
    {
        $videoId = '01a0b384-6e09-71a6-8f58-df48654d6aee';
        $subjectId = '01a09e44-539a-7f1a-938a-d7d91bb689a3';
        $source = [
            'platform' => 'youtube',
            'external_video_id' => '2Fx8Wp4Hzyk',
            'canonical_source_url' => 'https://www.youtube.com/watch?v=2Fx8Wp4Hzyk',
            'source_snapshot_hash' => 'immutable-source-hash',
            'source_revision' => 1,
        ];
        $repository = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection(), null, static function (array $context): array {
            $subject = $context['intended_targets'][0] ?? [];
            return ['status' => 'available', 'subject' => $subject, 'candidates' => [], 'diagnostics' => [], 'proposal_ready' => false, 'unresolved_reasons' => []];
        });

        $plan = $planner->plan([
            'operation' => 'ingest',
            'entity_type' => 'video',
            'expected_revision' => 1,
            'payload' => [
                'canonical_id' => $videoId,
                'metadata' => [
                    'source' => $source,
                    'knowledge_enrichment' => ['subject' => null, 'diagnostics' => ['NO_SUPPORTED_SUBJECT']],
                    'semantic_attachments' => [['target_uuid' => 'stale-target']],
                    'completeness' => ['publishable' => true],
                    'diagnostics' => ['STALE_DIAGNOSTIC'],
                ],
            ],
        ], [
            'continuation_delta_text' => '',
            'retrieval' => ['selected_claims' => []],
            'capture_id' => 'capture-legacy-video',
            'subject_resolution' => ['primary' => ['id' => $subjectId, 'type' => 'classification', 'revision' => 2, 'name' => 'Đồng hồ công cộng']],
        ]);

        self::assertSame('REBUILD_INGEST', $plan['status']);
        self::assertSame('ingest', $plan['operation']);
        self::assertNull($plan['expected_revision']);
        self::assertSame($videoId, $plan['payload']['canonical_id']);
        self::assertSame($source, $plan['payload']['metadata']['source']);
        self::assertSame($subjectId, $plan['payload']['metadata']['knowledge_enrichment']['subject']['id']);
        self::assertSame('classification', $plan['payload']['metadata']['knowledge_enrichment']['subject']['type']);
        self::assertSame([], $plan['payload']['metadata']['semantic_attachments']);
        self::assertNotContains('NO_SUPPORTED_SUBJECT', $plan['payload']['metadata']['knowledge_enrichment']['diagnostics']);
        self::assertNotContains('STALE_DIAGNOSTIC', $plan['payload']['metadata']['diagnostics']);
    }

    public function test_video_resume_fails_closed_when_external_identity_belongs_to_another_canonical_owner(): void
    {
        $expectedId = '01a0aaf8-2a84-7287-bbd8-70af4d5485e4';
        $repository = new VideoEditorialResumeTestRepository(new Video(
            '01a0b384-6e09-71a6-8f58-df48654d6aee',
            'youtube',
            'dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'Existing video',
            ['source' => ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ']],
        ));
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection());

        $this->expectExceptionMessage('VIDEO_IDENTITY_CONFLICT');
        $planner->plan(['payload' => [
            'canonical_id' => $expectedId,
            'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ']],
        ]], $this->resumeContext());
    }

    public function test_pre_apply_retry_does_not_reuse_stale_enrichment_when_recompute_fails(): void
    {
        $videoId = '01a0aaf8-2a84-7287-bbd8-70af4d5485e4';
        $repository = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection(), null, static function (array $context): array {
            throw new \RuntimeException('planner unavailable');
        });

        $plan = $planner->plan(['operation' => 'ingest', 'entity_type' => 'video', 'payload' => [
            'canonical_id' => $videoId,
            'metadata' => [
                'source' => ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'knowledge_enrichment' => ['status' => 'available', 'subject' => null, 'diagnostics' => ['NO_SUPPORTED_SUBJECT']],
            ],
        ]], $this->resumeContext() + ['subject_resolution' => ['primary' => ['id' => '01a09e44-539a-7f1a-938a-d7d91bb689a3', 'type' => 'classification']]]);

        self::assertSame('unavailable', $plan['payload']['metadata']['knowledge_enrichment']['status']);
        self::assertStringContainsString('KNOWLEDGE_ENRICHMENT_RECOMPUTE_FAILED', $plan['payload']['metadata']['knowledge_enrichment']['diagnostics'][0]);
        self::assertNotSame(['status' => 'available', 'subject' => null, 'diagnostics' => ['NO_SUPPORTED_SUBJECT']], $plan['payload']['metadata']['knowledge_enrichment']);
    }

    public function test_same_effective_resume_input_reuses_existing_fingerprint_without_update_plan(): void
    {
        $videoId = '01a07971-2fe3-77da-9424-998cf6f249e0';
        $repository = new VideoEditorialResumeTestRepository(new Video($videoId, 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Video', [
            'source' => ['external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'editorial' => ['title' => 'CURRENT', 'summary' => 'CURRENT SUMMARY', 'body' => 'CURRENT BODY', 'why_this_matters' => 'CURRENT WHY'],
            'editorial_input_fingerprint' => 'placeholder',
        ], null, true, 3));
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection());
        $context = ['continuation_delta_text' => '', 'subject_resolution' => ['primary' => ['id' => '22222222-2222-4222-8222-222222222222', 'type' => 'variant', 'name' => 'Odo 36/8']], 'retrieval' => ['selected_claims' => []]];
        $first = $planner->plan(['payload' => ['canonical_id' => $videoId]], $context);
        $repository->replaceMetadata($videoId, $first['payload']['metadata']);
        $repository->replaceTitle($videoId, (string) $first['payload']['title']);
        $second = $planner->plan(['payload' => ['canonical_id' => $videoId]], $context);

        self::assertSame('REBUILD_EDITORIAL', $first['status']);
        self::assertSame('REUSE_EDITORIAL', $second['status']);
        self::assertSame($first['fingerprint'], $second['fingerprint']);
        self::assertSame($videoId, $second['canonical_readback']['canonical_id']);

        $changedClaim = $context;
        $changedClaim['retrieval']['selected_claims'] = [['id' => 'claim-1', 'revision' => 2]];
        self::assertNotSame($second['fingerprint'], $planner->plan(['payload' => ['canonical_id' => $videoId]], $changedClaim)['fingerprint']);

        $unrelated = $context;
        $unrelated['fetched_at'] = 'later';
        self::assertSame($second['fingerprint'], $planner->plan(['payload' => ['canonical_id' => $videoId]], $unrelated)['fingerprint']);
    }

    public function test_matching_fingerprint_with_stale_canonical_title_rebuilds_same_video(): void
    {
        $video = $this->videoWithCanonicalPackage();
        $repository = new VideoEditorialResumeTestRepository($video);
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection());
        $context = $this->resumeContext();
        $first = $planner->plan(['payload' => ['canonical_id' => $video->canonicalId]], $context);
        $stale = $first['payload']['metadata'];
        $stale['editorial']['title'] = 'Video tham chiếu NHK';
        $repository->replaceMetadata($video->canonicalId, $stale);

        $retry = $planner->plan(['payload' => ['canonical_id' => $video->canonicalId]], $context);

        self::assertSame('REBUILD_EDITORIAL', $retry['status']);
        self::assertSame('update', $retry['operation']);
        self::assertSame($video->canonicalId, $retry['target_uuid']);
        self::assertSame($video->revision, $retry['expected_revision']);
        self::assertNotSame('Video tham chiếu NHK', $retry['payload']['metadata']['editorial']['title']);
    }

    public function test_matching_fingerprint_with_stale_top_level_canonical_title_rebuilds_same_video(): void
    {
        $video = $this->videoWithCanonicalPackage();
        $repository = new VideoEditorialResumeTestRepository($video);
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection());
        $context = $this->resumeContext();
        $first = $planner->plan(['payload' => ['canonical_id' => $video->canonicalId]], $context);

        // The persisted child fingerprint and metadata package are current,
        // but the canonical owner's reader-facing title is still stale.
        $repository->replaceMetadata($video->canonicalId, $first['payload']['metadata']);
        $repository->replaceTitle($video->canonicalId, 'Video tham chiếu NHK');

        $retry = $planner->plan(['payload' => ['canonical_id' => $video->canonicalId]], $context);

        self::assertSame('REBUILD_EDITORIAL', $retry['status']);
        self::assertSame('STALE_EDITORIAL_REPLAY', $retry['payload']['metadata']['editorial_reconciliation']['diagnostic']);
        self::assertSame($video->canonicalId, $retry['target_uuid']);
        self::assertSame($video->canonicalId, $retry['payload']['canonical_id']);
        self::assertNotSame('Video tham chiếu NHK', $retry['payload']['title']);
    }

    public function test_matching_fingerprint_replays_captured_editorial_package_and_updates_canonical_title(): void
    {
        $video = $this->videoWithCanonicalPackage();
        $repository = new VideoEditorialResumeTestRepository($video);
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection());
        $context = $this->resumeContext();
        $first = $planner->plan(['payload' => ['canonical_id' => $video->canonicalId]], $context);
        $desiredTitle = 'Đồng hồ vai bò Junghans W64 5 côn đồng bạch – chất âm rất đáng chơi';
        $desiredMetadata = $first['payload']['metadata'];
        $desiredMetadata['editorial']['title'] = $desiredTitle;
        $desiredMetadata['seo']['title'] = $desiredTitle;
        $desiredMetadata['seo_projection']['title'] = $desiredTitle;
        $desiredMetadata['seo_projection']['open_graph']['title'] = $desiredTitle;
        $desiredMetadata['seo_projection']['video_object']['name'] = $desiredTitle;
        $stale = $first['payload']['metadata'];
        $stale['editorial']['title'] = 'Video tham chiếu NHK';
        $stale['seo']['title'] = 'Video tham chiếu NHK';
        $repository->replaceMetadata($video->canonicalId, $stale);

        $retry = $planner->plan(['payload' => [
            'canonical_id' => $video->canonicalId,
            'title' => $desiredTitle,
            'metadata' => $desiredMetadata,
        ]], $context);

        self::assertSame('REBUILD_EDITORIAL', $retry['status']);
        self::assertSame($video->canonicalId, $retry['target_uuid']);
        self::assertSame($desiredTitle, $retry['payload']['title']);
        self::assertSame($desiredTitle, $retry['payload']['metadata']['editorial']['title']);
        self::assertSame('STALE_EDITORIAL_REPLAY', $retry['payload']['metadata']['editorial_reconciliation']['diagnostic']);
    }

    public function test_matching_fingerprint_with_stale_seo_projection_rebuilds_same_video(): void
    {
        $video = $this->videoWithCanonicalPackage();
        $repository = new VideoEditorialResumeTestRepository($video);
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection());
        $context = $this->resumeContext();
        $first = $planner->plan(['payload' => ['canonical_id' => $video->canonicalId]], $context);
        $stale = $first['payload']['metadata'];
        $stale['seo_projection']['title'] = 'Video tham chiếu NHK';
        $stale['seo_projection']['open_graph']['title'] = 'Video tham chiếu NHK';
        $repository->replaceMetadata($video->canonicalId, $stale);

        $retry = $planner->plan(['payload' => ['canonical_id' => $video->canonicalId]], $context);

        self::assertSame('REBUILD_EDITORIAL', $retry['status']);
        self::assertSame($video->canonicalId, $retry['target_uuid']);
        self::assertSame($retry['payload']['metadata']['seo_projection']['title'], $retry['payload']['metadata']['seo_projection']['open_graph']['title']);
    }

    public function test_matching_fingerprint_with_stale_subject_packet_rebuilds_same_video(): void
    {
        $video = $this->videoWithCanonicalPackage();
        $repository = new VideoEditorialResumeTestRepository($video);
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection());
        $context = $this->resumeContext();
        $first = $planner->plan(['payload' => ['canonical_id' => $video->canonicalId]], $context);
        $stale = $first['payload']['metadata'];
        $stale['subject_resolution_packet']['id'] = '33333333-3333-4333-8333-333333333333';
        $repository->replaceMetadata($video->canonicalId, $stale);

        $retry = $planner->plan(['payload' => ['canonical_id' => $video->canonicalId]], $context);

        self::assertSame('REBUILD_EDITORIAL', $retry['status']);
        self::assertSame($video->canonicalId, $retry['target_uuid']);
        self::assertSame($context['subject_resolution']['primary']['id'], $retry['payload']['metadata']['subject_resolution_packet']['id']);
    }

    private function videoWithCanonicalPackage(): Video
    {
        return new Video(
            '01a07971-2fe3-77da-9424-998cf6f249e0',
            'youtube',
            'dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'Nguồn video gốc',
            [
                'source' => ['external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'source_title' => 'Nguồn video gốc'],
                'editorial_input_fingerprint' => 'placeholder',
                'editorial' => ['title' => 'OLD TITLE', 'summary' => 'OLD SUMMARY', 'body' => 'OLD BODY', 'why_this_matters' => 'OLD WHY'],
                'seo' => ['title' => 'OLD SEO', 'description' => 'OLD SEO DESCRIPTION'],
                'semantic_attachments' => [],
            ],
            null,
            true,
            3,
        );
    }

    /** @return array<string,mixed> */
    private function resumeContext(): array
    {
        return [
            'continuation_delta_text' => '',
            'subject_resolution' => ['primary' => ['id' => '22222222-2222-4222-8222-222222222222', 'type' => 'variant', 'name' => 'Junghans W64']],
            'retrieval' => ['selected_claims' => []],
        ];
    }
}

final class VideoEditorialResumeTestRepository implements VideoRepository
{
    /** @var array<string,Video> */
    private array $items;

    public function __construct(Video $video) { $this->items[$video->canonicalId] = $video; }
    public function findByCanonicalId(string $id): ?Video { return $this->items[$id] ?? null; }
    public function findByExternalReference(string $platform, string $externalId): ?Video { foreach ($this->items as $video) if ($video->platform === $platform && $video->externalVideoId === $externalId) return $video; return null; }
    public function create(Video $video): Video { return $this->items[$video->canonicalId] = $video; }
    public function update(Video $video, int $expectedRevision): Video
    {
        $current = $this->items[$video->canonicalId] ?? null;
        if ($current === null || $current->revision !== $expectedRevision) throw new \RuntimeException('Video revision conflict.');
        return $this->items[$video->canonicalId] = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $video->metadata, $video->thumbnailMediaId, $video->active, $expectedRevision + 1);
    }
    public function list(bool $includeRetired = false): array { return array_values($this->items); }
    public function replaceMetadata(string $id, array $metadata): void
    {
        $video = $this->items[$id];
        $this->items[$id] = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $metadata, $video->thumbnailMediaId, $video->active, $video->revision);
    }
    public function replaceTitle(string $id, string $title): void
    {
        $video = $this->items[$id];
        $this->items[$id] = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $title, $video->metadata, $video->thumbnailMediaId, $video->active, $video->revision);
    }
}
