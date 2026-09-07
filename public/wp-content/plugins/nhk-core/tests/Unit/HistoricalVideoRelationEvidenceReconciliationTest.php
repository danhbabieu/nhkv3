<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Application\Video\HistoricalVideoRelationEvidenceReconciliation;
use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use PHPUnit\Framework\TestCase;

final class HistoricalVideoRelationEvidenceReconciliationTest extends TestCase
{
    public function test_historical_missing_evidence_is_reconciled_and_approval_is_rebound(): void
    {
        $videoId = '01a07971-2fe3-77da-9424-998cf6f249e0';
        $relation = $this->relation('legacy-relation', $videoId);
        $repo = new ReconciliationProposalRepository($relation);
        $claims = new ReconciliationClaimRepository();
        $sources = new ReconciliationSourceRepository();
        $evidence = new ReconciliationEvidenceRepository();

        $result = (new HistoricalVideoRelationEvidenceReconciliation(new KnowledgeService($claims, $sources, $evidence), $claims, $sources, $evidence, $repo))
            ->reconcile($videoId, 'video-binding', ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'source_title' => 'Canonical source'], [$relation]);

        self::assertSame('rebound', $result[0]['status']);
        self::assertSame(ProposalState::SUPERSEDED, $repo->find('legacy-relation')?->state);
        self::assertSame(1, count($repo->approved));
        self::assertNotSame('legacy-relation', $repo->approved[0]->id);
        self::assertNotEmpty($repo->latestApproval($repo->approved[0]->id));
        self::assertNotEmpty($repo->approved[0]->payload['evidence_refs']);
        self::assertCount(1, $sources->items);
        self::assertCount(1, $evidence->items);
    }

    public function test_wrong_source_evidence_and_fingerprint_mismatch_fail_closed(): void
    {
        $videoId = '01a07971-2fe3-77da-9424-998cf6f249e0';
        $relation = $this->relation('wrong-source', $videoId, 'wrong-binding');
        $repo = new ReconciliationProposalRepository($relation);
        $claims = new ReconciliationClaimRepository();
        $sources = new ReconciliationSourceRepository();
        $wrongSource = new Source('44444444-4444-4444-8444-444444444444', 'wrong', 'Wrong', 'website', 'https://wrong.example');
        $sources->items[$wrongSource->canonicalId] = $wrongSource;
        $evidence = new ReconciliationEvidenceRepository();
        $claim = new KnowledgeClaim('55555555-5555-4555-8555-555555555555', 'claim', 'A claim.');
        $claims->items[$claim->canonicalId] = $claim;
        $evidence->items['66666666-6666-4666-8666-666666666666'] = new Evidence('66666666-6666-4666-8666-666666666666', $claim->canonicalId, $wrongSource->canonicalId, 'supports', 'Excerpt.');

        $service = new HistoricalVideoRelationEvidenceReconciliation(new KnowledgeService($claims, $sources, $evidence), $claims, $sources, $evidence, $repo);
        $this->expectExceptionMessage('FINGERPRINT_MISMATCH');
        $service->reconcile($videoId, 'video-binding', ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'], [$relation]);
    }

    public function test_existing_evidence_is_reused_and_wrong_source_is_rejected(): void
    {
        $videoId = '01a07971-2fe3-77da-9424-998cf6f249e0';
        $repo = new ReconciliationProposalRepository($this->relation('seed', $videoId));
        $claims = new ReconciliationClaimRepository(); $sources = new ReconciliationSourceRepository(); $evidence = new ReconciliationEvidenceRepository();
        $service = new HistoricalVideoRelationEvidenceReconciliation(new KnowledgeService($claims, $sources, $evidence), $claims, $sources, $evidence, $repo);
        $source = ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        $service->reconcile($videoId, 'video-binding', $source, [$repo->find('seed')]);
        $evidenceId = array_key_first($evidence->items);
        $existing = $this->relation('existing', $videoId, 'video-binding', [['evidence_id' => $evidenceId]]);
        $repo->create($existing);
        $service->reconcile($videoId, 'video-binding', $source, [$existing]);
        self::assertCount(1, $evidence->items);

        $wrongSource = new Source('77777777-7777-4777-8777-777777777777', 'wrong-source', 'Wrong source', 'website', 'https://wrong.example');
        $sources->items[$wrongSource->canonicalId] = $wrongSource;
        $claimId = array_key_first($claims->items);
        $wrongEvidence = new Evidence('66666666-6666-4666-8666-666666666666', $claimId, $wrongSource->canonicalId, 'supports', 'Wrong-source excerpt.');
        $evidence->items[$wrongEvidence->canonicalId] = $wrongEvidence;
        $wrong = $this->relation('wrong-evidence', $videoId, 'video-binding', [['evidence_id' => '66666666-6666-4666-8666-666666666666']]);
        $repo->create($wrong);
        $this->expectExceptionMessage('WRONG_SOURCE_EVIDENCE');
        $service->reconcile($videoId, 'video-binding', $source, [$wrong]);
    }

    public function test_replay_is_idempotent_and_graph_policy_remains_fail_closed(): void
    {
        $videoId = '01a07971-2fe3-77da-9424-998cf6f249e0';
        $relation = $this->relation('replay', $videoId);
        $repo = new ReconciliationProposalRepository($relation);
        $claims = new ReconciliationClaimRepository(); $sources = new ReconciliationSourceRepository(); $evidence = new ReconciliationEvidenceRepository();
        $service = new HistoricalVideoRelationEvidenceReconciliation(new KnowledgeService($claims, $sources, $evidence), $claims, $sources, $evidence, $repo);
        $source = ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        $first = $service->reconcile($videoId, 'video-binding', $source, [$relation]);
        $second = $service->reconcile($videoId, 'video-binding', $source, [$relation]);
        self::assertSame($first[0]['replacement_id'], $second[0]['replacement_id']);
        self::assertCount(1, $sources->items); self::assertCount(1, $evidence->items);
    }

    private function relation(string $id, string $videoId, string $fingerprint = 'video-binding', array $evidenceRefs = []): Proposal
    {
        return new Proposal($id, 'relation', 'relation_create', ['source_type' => 'video', 'source_uuid' => $videoId, 'target_type' => 'brand', 'target_uuid' => '22222222-2222-4222-8222-222222222222', 'predicate' => 'about', 'evidence_refs' => $evidenceRefs, 'reason' => 'legacy reason', 'source_fingerprint' => $fingerprint], 'content', null, 'dependency', ProposalState::APPROVED, decisionActor: 'reviewer', idempotencyKey: 'legacy:' . $id, entityType: 'relation');
    }
}

final class ReconciliationProposalRepository implements ProposalRepository
{
    public array $items = []; public array $approvals = []; public array $approved = [];
    public function __construct(Proposal $proposal) { $this->items[$proposal->id] = $proposal; }
    public function create(Proposal $proposal): Proposal { $this->items[$proposal->id] = $proposal; return $proposal; }
    public function find(string $id): ?Proposal { return $this->items[$id] ?? null; }
    public function findByIdempotencyKey(string $key): ?Proposal { foreach ($this->items as $item) if ($item->idempotencyKey === $key) return $item; return null; }
    public function save(Proposal $proposal): Proposal { return $this->items[$proposal->id] = $proposal; }
    public function findForUpdate(string $id): ?Proposal { return $this->find($id); }
    public function recordApproval(Proposal $proposal, string $actor): void { $this->approvals[$proposal->id] = ['proposal_revision' => $proposal->revision, 'fingerprint' => $proposal->bindingFingerprint(), 'approved_by' => $actor]; $this->approved[] = $proposal; }
    public function latestApproval(string $proposalId): ?array { return $this->approvals[$proposalId] ?? null; }
}
final class ReconciliationClaimRepository implements KnowledgeRepository { public array $items=[]; public function findByCanonicalId(string $id): ?KnowledgeClaim{return $this->items[$id]??null;} public function findByStableKey(string $key): ?KnowledgeClaim{foreach($this->items as $v)if($v->stableKey===$key)return $v;return null;} public function create(KnowledgeClaim $c):KnowledgeClaim{return $this->items[$c->canonicalId]=$c;} public function update(KnowledgeClaim $c,int $r):KnowledgeClaim{return $this->items[$c->canonicalId]=$c;} public function list(bool $includeRetired=false):array{return array_values($this->items);} }
final class ReconciliationSourceRepository implements SourceRepository { public array $items=[]; public function findByCanonicalId(string $id): ?Source{return $this->items[$id]??null;} public function findByStableKey(string $key): ?Source{foreach($this->items as $v)if($v->stableKey===$key)return $v;return null;} public function create(Source $s):Source{return $this->items[$s->canonicalId]=$s;} public function update(Source $s,int $r):Source{return $this->items[$s->canonicalId]=$s;} public function list(bool $includeRetired=false):array{return array_values($this->items);} }
final class ReconciliationEvidenceRepository implements EvidenceRepository { public array $items=[]; public function findByCanonicalId(string $id): ?Evidence{return $this->items[$id]??null;} public function create(Evidence $e):Evidence{return $this->items[$e->canonicalId]=$e;} public function update(Evidence $e,int $r):Evidence{return $this->items[$e->canonicalId]=$e;} public function listByClaim(string $id,bool $includeRetired=false):array{return array_values(array_filter($this->items,fn(Evidence $e)=>$e->claimId===$id));} public function listBySource(string $id,bool $includeRetired=false):array{return array_values(array_filter($this->items,fn(Evidence $e)=>$e->sourceId===$id));} }
