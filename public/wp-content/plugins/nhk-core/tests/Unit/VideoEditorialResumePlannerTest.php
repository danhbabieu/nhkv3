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
        $second = $planner->plan(['payload' => ['canonical_id' => $videoId]], $context);

        self::assertSame('REBUILD_EDITORIAL', $first['status']);
        self::assertSame('REUSE_EDITORIAL', $second['status']);
        self::assertSame($first['fingerprint'], $second['fingerprint']);
        self::assertSame($videoId, $second['canonical_readback']['canonical_id']);
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
}
