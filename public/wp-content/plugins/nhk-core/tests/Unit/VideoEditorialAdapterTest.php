<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\ClaimRetrievalEngine;
use NHK\Core\Application\Video\{VideoEditorialAdapter, VideoEditorialGenerator, VideoEditorialResumePlanner, VideoSeoProjection};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\Video;
use PHPUnit\Framework\TestCase;

final class VideoEditorialAdapterTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';

    public function test_video_adapter_uses_shared_video_profile_and_preserves_topic_trace(): void
    {
        $adapter = $this->adapter([
            ['id' => 'claim-v1', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Odo 36 có ba phiên bản vách máy gồm vách cam, vách xoáy và cấu trúc 3 vách.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE'],
        ]);

        $result = $adapter->prepare([
            'source' => ['source_title' => '3 Phiên Bản Vách Của Đồng Hồ ÔĐô 36', 'platform' => 'youtube', 'external_video_id' => 'rUoJYDe8h3c'],
            'raw_input' => 'Đây là 3 phiên bản máy của dòng đồng hồ odo 36',
            'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model', 'name' => 'Odo 36']],
            'public_identity' => ['canonical_url' => '/video/odo-36/', 'canonical_identity' => true, 'public_eligible' => true],
        ]);

        self::assertSame('READY', $result['quality_report']->readiness);
        self::assertSame('video', $result['draft']->profile);
        self::assertSame('claim-v1', $result['draft']->claimTrace[0]['claim_id']);
        self::assertSame('/video/odo-36/', $result['seo_plan']->canonicalUrl);
        self::assertSame('claim-v1', $result['fingerprint_claims'][0]['id']);
    }

    public function test_video_adapter_blocks_without_public_identity_or_eligible_evidence(): void
    {
        $adapter = $this->adapter([
            ['id' => 'claim-v1', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Odo 36 có ba phiên bản vách máy.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'MISSING'],
        ]);

        $result = $adapter->prepare([
            'raw_input' => 'Đây là 3 phiên bản máy của dòng đồng hồ odo 36',
            'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model', 'name' => 'Odo 36']],
            'public_identity' => ['canonical_url' => '', 'canonical_identity' => false, 'public_eligible' => false],
        ]);

        self::assertSame('BLOCKED', $result['quality_report']->readiness);
        self::assertContains('SEO_NOT_READY', $result['quality_report']->blockers);
        self::assertContains('MISSING_PUBLIC_IDENTITY', $result['seo_plan']->blockers);
    }

    public function test_resume_planner_does_not_bypass_blocked_shared_quality_with_legacy_prose(): void
    {
        $videoId = '01a0c8f0-7b68-781f-963d-75fd2937e0cc';
        $repository = new class($videoId) implements VideoRepository {
            private Video $video;
            public function __construct(string $id) { $this->video = new Video($id, 'youtube', 'rUoJYDe8h3c', 'https://www.youtube.com/watch?v=rUoJYDe8h3c', 'Video gốc', ['source' => ['platform' => 'youtube', 'external_video_id' => 'rUoJYDe8h3c', 'canonical_source_url' => 'https://www.youtube.com/watch?v=rUoJYDe8h3c']]); }
            public function findByCanonicalId(string $id): ?Video { return $id === $this->video->canonicalId ? $this->video : null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return [$this->video]; }
        };
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection(), null, null, $this->adapter([]));

        $this->expectExceptionMessage('VIDEO_EDITORIAL_QUALITY_BLOCKED');
        $planner->plan(['payload' => ['canonical_id' => $videoId]], [
            'continuation_delta_text' => 'Đây là 3 phiên bản máy của dòng đồng hồ odo 36',
            'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model', 'name' => 'Odo 36']],
            'retrieval' => ['selected_claims' => []],
        ]);
    }

    private function adapter(array $rows): VideoEditorialAdapter
    {
        $engine = new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static fn (array $subject, array $neighborhood): array => $rows,
        );

        return VideoEditorialAdapter::fromEngine($engine);
    }
}
