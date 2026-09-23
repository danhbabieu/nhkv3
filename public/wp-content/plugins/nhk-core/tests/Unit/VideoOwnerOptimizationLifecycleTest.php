<?php
declare(strict_types=1);

namespace NHK\Core\Tests\Unit;

use NHK\Core\Application\Video\VideoService;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Domain\Video\VideoException;
use PHPUnit\Framework\TestCase;

final class VideoOwnerOptimizationLifecycleTest extends TestCase
{
    public function test_owner_can_be_admitted_with_sparse_optional_enrichment_then_optimized_after_readback(): void
    {
        $repository = new class implements VideoRepository {
            public ?Video $item = null;
            public function findByCanonicalId(string $id): ?Video { return $this->item?->canonicalId === $id ? $this->item : null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return $this->item?->platform === $platform && $this->item?->externalVideoId === $externalId ? $this->item : null; }
            public function create(Video $video): Video { $this->item = $video; return $video; }
            public function update(Video $video, int $expectedRevision): Video
            {
                if ($this->item === null || $this->item->revision !== $expectedRevision) throw new VideoException('VIDEO_REVISION_CONFLICT');
                $this->item = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $video->metadata, $video->thumbnailMediaId, $video->active, $expectedRevision + 1);
                return $this->item;
            }
            public function list(bool $includeRetired = false): array { return $this->item === null ? [] : [$this->item]; }
        };
        $service = new VideoService($repository);

        $owner = $service->ingestUrl('https://youtube.com/shorts/9bZkp7q19f0', 'Nguồn ngắn', [
            'enrichment' => ['status' => 'OPTIONAL_UNAVAILABLE'],
            'editorial' => ['summary' => 'Mô tả tối thiểu'],
        ]);
        $readBack = $service->find($owner->canonicalId);
        self::assertNotNull($readBack);

        $optimized = $service->update($owner->canonicalId, 'Tiêu đề đã tối ưu', [
            'enrichment' => ['status' => 'OPTIONAL_UNAVAILABLE'],
            'editorial' => ['summary' => 'Tóm tắt reader-safe'],
            'seo' => ['title' => 'Tiêu đề SEO'],
        ], null, $readBack->revision);

        self::assertSame($owner->canonicalId, $optimized->canonicalId);
        self::assertSame(2, $optimized->revision);
        self::assertSame('Tiêu đề SEO', $optimized->metadata['seo']['title']);
    }

    public function test_optimization_with_stale_owner_revision_fails_without_mutating_canonical_owner(): void
    {
        $repository = new class implements VideoRepository {
            public Video $item;
            public function __construct() { $this->item = new Video('11111111-1111-4111-8111-111111111111', 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Original'); }
            public function findByCanonicalId(string $id): ?Video { return $id === $this->item->canonicalId ? $this->item : null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { throw new VideoException('VIDEO_REVISION_CONFLICT'); }
            public function list(bool $includeRetired = false): array { return [$this->item]; }
        };
        $service = new VideoService($repository);

        try {
            $service->update($repository->item->canonicalId, 'Should not persist', [], null, 0);
            self::fail('Expected stale optimization to fail.');
        } catch (VideoException $error) {
            self::assertSame('VIDEO_REVISION_CONFLICT', $error->getMessage());
        }

        self::assertSame('Original', $repository->item->title);
    }
}
