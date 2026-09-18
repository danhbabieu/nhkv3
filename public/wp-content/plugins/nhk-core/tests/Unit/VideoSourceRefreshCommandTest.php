<?php
declare(strict_types=1);

namespace NHK\Core\Tests\Unit;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Video\VideoSourceRefreshCommand;
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\{Video, VideoException};
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class VideoSourceRefreshCommandTest extends TestCase
{
    public function testCreatesBoundedProposalAndReplaysIt(): void
    {
        $id = UuidCodec::newV7();
        $repo = new RefreshVideoRepository(new Video($id, 'youtube', 'abc12345678', 'https://www.youtube.com/watch?v=abc12345678', 'NHK title', [
            'source' => ['platform' => 'youtube', 'external_video_id' => 'abc12345678', 'canonical_source_url' => 'https://www.youtube.com/watch?v=abc12345678', 'source_revision' => 2],
            'editorial' => ['title' => 'NHK title'],
        ]));
        $proposals = new RefreshProposalRepository();
        $governance = new GovernanceService($proposals);
        $command = new VideoSourceRefreshCommand($repo, $governance, static fn (): array => [
            'source_title' => 'Fresh source',
            'availability' => 'available',
            'thumbnail_candidates' => [['variant' => 'mqdefault', 'url' => 'https://i.ytimg.com/vi/abc12345678/mqdefault.jpg', 'width' => 320, 'height' => 180]],
            'thumbnail_presentation' => ['variant' => 'mqdefault', 'url' => 'https://i.ytimg.com/vi/abc12345678/mqdefault.jpg', 'width' => 320, 'height' => 180],
        ]);

        $first = $command->prepare($id, 1, 2, 'refresh-1');
        self::assertSame('PROPOSAL_CREATED', $first['status']);
        self::assertSame(2, $first['payload']['expected_source_revision']);
        self::assertSame('source', $first['payload']['source_key']);
        $replay = $command->prepare($id, 1, 2, 'refresh-1');
        self::assertTrue($replay['idempotent']);
        self::assertSame($first['proposal_id'], $replay['proposal_id']);
    }

    public function testChangedPayloadAndStaleRevisionFailClosed(): void
    {
        $id = UuidCodec::newV7();
        $repo = new RefreshVideoRepository(new Video($id, 'youtube', 'abc12345678', 'https://www.youtube.com/watch?v=abc12345678', '', ['source' => ['platform' => 'youtube', 'external_video_id' => 'abc12345678', 'canonical_source_url' => 'https://www.youtube.com/watch?v=abc12345678', 'source_revision' => 1]]));
        $proposals = new RefreshProposalRepository();
        $command = new VideoSourceRefreshCommand($repo, new GovernanceService($proposals), static fn (): array => ['availability' => 'available']);
        $command->prepare($id, 1, 1, 'same-key');
        $this->expectException(VideoException::class);
        $this->expectExceptionMessage('IDEMPOTENCY_KEY_CONFLICT');
        $command->prepare($id, 1, 0, 'same-key');
    }

    public function testSourceFailureDoesNotCreateProposal(): void
    {
        $id = UuidCodec::newV7();
        $repo = new RefreshVideoRepository(new Video($id, 'youtube', 'abc12345678', 'https://www.youtube.com/watch?v=abc12345678', '', ['source' => ['source_revision' => 1]]));
        $proposals = new RefreshProposalRepository();
        $command = new VideoSourceRefreshCommand($repo, new GovernanceService($proposals), static function (): array { throw new VideoException('SOURCE_TIMEOUT'); });
        $result = $command->prepare($id, 1, 1, 'failure-1');
        self::assertSame('SOURCE_UNAVAILABLE', $result['status']);
        self::assertCount(0, $proposals->items);
    }

    public function testApplyChangesOnlySourceMetadataAndKeepsIdentityAndEditorialState(): void
    {
        $id = UuidCodec::newV7();
        $repo = new RefreshVideoRepository(new Video($id, 'youtube', 'abc12345678', 'https://www.youtube.com/watch?v=abc12345678', 'Editorial title', [
            'source' => ['platform' => 'youtube', 'external_video_id' => 'abc12345678', 'canonical_source_url' => 'https://www.youtube.com/watch?v=abc12345678', 'source_revision' => 1],
            'editorial' => ['title' => 'Protected title'],
            'semantic_attachments' => [['target_uuid' => UuidCodec::newV7(), 'predicate' => 'about']],
        ], null, true, 1));
        $updated = (new VideoService($repo))->applySourceRefresh($id, [
            'platform' => 'youtube', 'external_video_id' => 'abc12345678', 'canonical_source_url' => 'https://www.youtube.com/watch?v=abc12345678', 'source_revision' => 2,
            'thumbnail_candidates' => [['variant' => 'mqdefault', 'url' => 'https://i.ytimg.com/vi/abc12345678/mqdefault.jpg', 'width' => 320, 'height' => 180]],
        ], 'source', 1, 1);
        self::assertSame($id, $updated->canonicalId);
        self::assertSame('abc12345678', $updated->externalVideoId);
        self::assertSame('Editorial title', $updated->title);
        self::assertSame('Protected title', $updated->metadata['editorial']['title']);
        self::assertSame(2, $updated->metadata['source']['source_revision']);
        self::assertSame(2, $updated->revision);
    }
}

final class RefreshVideoRepository implements VideoRepository
{
    public function __construct(private Video $video) {}
    public function findByCanonicalId(string $id): ?Video { return $this->video->canonicalId === $id ? $this->video : null; }
    public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
    public function create(Video $video): Video { return $video; }
    public function update(Video $video, int $expectedRevision): Video { $this->video = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $video->metadata, $video->thumbnailMediaId, $video->active, $expectedRevision + 1); return $this->video; }
    public function list(bool $includeRetired = false): array { return [$this->video]; }
}

final class RefreshProposalRepository implements \NHK\Core\Contracts\Governance\ProposalRepository
{
    /** @var list<Proposal> */ public array $items = [];
    public function create(Proposal $proposal): Proposal { $this->items[] = $proposal; return $proposal; }
    public function find(string $id): ?Proposal { foreach ($this->items as $item) if ($item->id === $id) return $item; return null; }
    public function findByIdempotencyKey(string $key): ?Proposal { foreach ($this->items as $item) if ($item->idempotencyKey === $key) return $item; return null; }
    public function save(Proposal $proposal): Proposal { foreach ($this->items as $i => $item) if ($item->id === $proposal->id) return $this->items[$i] = $proposal; return $proposal; }
    public function findForUpdate(string $id): ?Proposal { return $this->find($id); }
    public function recordApproval(Proposal $proposal, string $actor): void {}
    public function latestApproval(string $proposalId): ?array { return null; }
    public function findLatestVideoIngest(string $videoId): ?Proposal { return null; }
}
