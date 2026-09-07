<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Application\Video\VideoRelationAdminService;
use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class VideoRelationAdminServiceTest extends TestCase
{
    public function test_context_and_create_resolve_video_proposal_and_canonical_evidence_without_manual_uuids(): void
    {
        $videoId = '01a07971-2fe3-77da-9424-998cf6f249e0'; $targetId = '22222222-2222-4222-8222-222222222222';
        $video = new Video($videoId, 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Video', ['source_snapshot' => ['title' => 'YouTube source', 'description' => 'Source description']]);
        $videos = new class($video) implements VideoRepository { public function __construct(private Video $video) {} public function findByCanonicalId(string $id): ?Video { return $id === $this->video->canonicalId ? $this->video : null; } public function findByExternalReference(string $platform,string $externalId):?Video{return null;} public function create(Video $video):Video{return $video;} public function update(Video $video,int $expectedRevision):Video{return $video;} public function list(bool $includeRetired=false):array{return [$this->video];} };
        $proposals = new AdminProposalRepository(); $videoProposal = new Proposal('01a07971-2fe3-77da-9424-998cf6f249e1', 'video', 'ingest', ['canonical_id' => $videoId], 'video-content', null, 'video-dependency', ProposalState::DRAFT, actor: '1', idempotencyKey: 'video-ingest', entityType: 'video'); $proposals->create($videoProposal);
        $authority = new InMemoryAuthorityRepository(); $authority->create(new AuthorityEntity($targetId, 'brand', 'brand.example', 'Example', 1, []));
        $claims = new AdminClaimRepository(); $sources = new AdminSourceRepository(); $evidence = new AdminEvidenceRepository();
        $service = new VideoRelationAdminService(new GovernanceService($proposals), $proposals, $videos, $authority, new KnowledgeService($claims, $sources, $evidence), $claims, $sources, $evidence);

        $context = $service->context($videoId); self::assertSame($videoProposal->id, $context['video_proposal']['id']); self::assertSame('PRIVATE', $context['provenance']['visibility']);
        $result = $service->create($videoId, 'brand', $targetId, '1');
        self::assertSame('DRAFT', $result['state']); self::assertNotEmpty($result['evidence']['id']); self::assertSame('PRIVATE', $result['evidence']['visibility']); self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $result['evidence']['locator']); self::assertCount(1, $sources->list()); self::assertCount(1, $claims->list()); self::assertCount(1, $evidence->listByClaim($result['evidence']['claim_id']));
        self::assertSame($result['evidence']['id'], $proposals->find($result['proposal_id'])?->payload['evidence_refs'][0]['evidence_id']);
    }

    public function test_replay_reuses_evidence_and_relation_proposal(): void
    {
        [$service, $proposals, $sources, $claims, $evidence, $videoId, $targetId] = $this->fixture();
        $first = $service->create($videoId, 'brand', $targetId, '1'); $second = $service->create($videoId, 'brand', $targetId, '1');
        self::assertSame($first['proposal_id'], $second['proposal_id']); self::assertSame($first['evidence']['id'], $second['evidence']['id']); self::assertCount(1, $sources->list()); self::assertCount(1, $claims->list()); self::assertCount(1, $evidence->listByClaim($first['evidence']['claim_id'])); self::assertCount(1, $proposals->items);
    }

    public function test_missing_or_invalid_video_provenance_fails_closed(): void
    {
        [$service, , , , , $videoId, $targetId] = $this->fixture(new Video($videoId = '01a07971-2fe3-77da-9424-998cf6f249e0', 'vimeo', 'dQw4w9WgXcQ', 'https://example.test/not-youtube', 'Video'));
        $this->expectExceptionMessage('CANONICAL_VIDEO_PROVENANCE_REQUIRED'); $service->create($videoId, 'brand', $targetId, '1');
    }

    /** @return array{VideoRelationAdminService,AdminProposalRepository,object,object,object,string,string} */
    private function fixture(?Video $video = null): array
    {
        $videoId = '01a07971-2fe3-77da-9424-998cf6f249e0'; $targetId = '22222222-2222-4222-8222-222222222222'; $video ??= new Video($videoId, 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Video', ['source_snapshot' => ['title' => 'Source']]);
        $videos = new class($video) implements VideoRepository { public function __construct(private Video $video) {} public function findByCanonicalId(string $id):?Video{return $id===$this->video->canonicalId?$this->video:null;} public function findByExternalReference(string $platform,string $externalId):?Video{return null;} public function create(Video $video):Video{return $video;} public function update(Video $video,int $expectedRevision):Video{return $video;} public function list(bool $includeRetired=false):array{return [$this->video];} };
        $proposals = new AdminProposalRepository(); $proposals->create(new Proposal('01a07971-2fe3-77da-9424-998cf6f249e1', 'video', 'ingest', ['canonical_id' => $videoId], 'video-content', null, 'video-dependency', ProposalState::DRAFT, actor: '1', idempotencyKey: 'video-ingest', entityType: 'video'));
        $authority = new InMemoryAuthorityRepository(); $authority->create(new AuthorityEntity($targetId, 'brand', 'brand.example', 'Example', 1, [])); $claims = new AdminClaimRepository(); $sources = new AdminSourceRepository(); $evidence = new AdminEvidenceRepository(); $knowledge = new KnowledgeService($claims, $sources, $evidence);
        return [new VideoRelationAdminService(new GovernanceService($proposals), $proposals, $videos, $authority, $knowledge, $claims, $sources, $evidence), $proposals, $sources, $claims, $evidence, $videoId, $targetId];
    }
}

final class AdminClaimRepository implements KnowledgeRepository { public array $items=[]; public function findByCanonicalId(string $id):?KnowledgeClaim{return $this->items[$id]??null;} public function findByStableKey(string $key):?KnowledgeClaim{foreach($this->items as $item)if($item->stableKey===$key)return $item;return null;} public function create(KnowledgeClaim $claim):KnowledgeClaim{return $this->items[$claim->canonicalId]=$claim;} public function update(KnowledgeClaim $claim,int $expectedRevision):KnowledgeClaim{return $this->items[$claim->canonicalId]=$claim;} public function list(bool $includeRetired=false):array{return array_values($this->items);} }
final class AdminSourceRepository implements SourceRepository { public array $items=[]; public function findByCanonicalId(string $id):?Source{return $this->items[$id]??null;} public function findByStableKey(string $key):?Source{foreach($this->items as $item)if($item->stableKey===$key)return $item;return null;} public function create(Source $source):Source{return $this->items[$source->canonicalId]=$source;} public function update(Source $source,int $expectedRevision):Source{return $this->items[$source->canonicalId]=$source;} public function list(bool $includeRetired=false):array{return array_values($this->items);} }
final class AdminEvidenceRepository implements EvidenceRepository { public array $items=[]; public function findByCanonicalId(string $id):?Evidence{return $this->items[$id]??null;} public function create(Evidence $evidence):Evidence{return $this->items[$evidence->canonicalId]=$evidence;} public function update(Evidence $evidence,int $expectedRevision):Evidence{return $this->items[$evidence->canonicalId]=$evidence;} public function listByClaim(string $id,bool $includeRetired=false):array{return array_values(array_filter($this->items,fn(Evidence $item)=>$item->claimId===$id));} public function listBySource(string $id,bool $includeRetired=false):array{return array_values(array_filter($this->items,fn(Evidence $item)=>$item->sourceId===$id));} }

final class AdminProposalRepository implements ProposalRepository
{
    public array $items = [];
    public function create(Proposal $proposal): Proposal { return $this->items[$proposal->id] = $proposal; }
    public function find(string $id): ?Proposal { return $this->items[$id] ?? null; }
    public function findByIdempotencyKey(string $key): ?Proposal { foreach ($this->items as $item) if ($item->idempotencyKey === $key) return $item; return null; }
    public function save(Proposal $proposal): Proposal { return $this->items[$proposal->id] = $proposal; }
    public function findForUpdate(string $id): ?Proposal { return $this->find($id); }
    public function recordApproval(Proposal $proposal, string $actor): void {}
    public function latestApproval(string $proposalId): ?array { return null; }
    public function findLatestVideoIngest(string $videoId): ?Proposal { foreach (array_reverse($this->items) as $item) if ($item->entityType === 'video' && $item->operation === 'ingest' && ($item->payload['canonical_id'] ?? '') === $videoId) return $item; return null; }
}
