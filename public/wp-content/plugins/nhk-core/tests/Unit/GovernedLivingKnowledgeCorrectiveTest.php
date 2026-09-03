<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Knowledge\{CurrentTruthPacket, KnowledgeEnrichmentPlanner, KnowledgeFragmentProjector};
use NHK\Core\Application\Seo\LivingKnowledgeSeoStabilityGuard;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, KnowledgeFacetProfile, Source};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class GovernedLivingKnowledgeCorrectiveTest extends TestCase
{
    public function test_retired_exact_match_is_not_current_same_claim(): void
    {
        $subject = UuidCodec::newV7(); $profile = new KnowledgeFacetProfile('recognition', 'variant');
        $retired = new KnowledgeClaim(UuidCodec::newV7(), 'retired', 'Cọc đen.', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant']], false);
        $result = $this->planner([$retired])->plan($subject, $profile, 'Cọc đen.');
        self::assertSame('new_claim', $result[0]->classification);
    }

    public function test_explicit_evidence_context_classifies_add_qualify_and_contradict(): void
    {
        $subject = UuidCodec::newV7(); $profile = new KnowledgeFacetProfile('recognition', 'variant');
        $claim = new KnowledgeClaim(UuidCodec::newV7(), 'current', 'Cọc đen.', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant']]);
        $planner = $this->planner([$claim]);
        self::assertSame('add_evidence', $planner->plan($subject, $profile, 'Ảnh xác nhận.', ['relation' => 'supports', 'claim_id' => $claim->canonicalId])[0]->classification);
        self::assertSame('qualify', $planner->plan($subject, $profile, 'Một số trường hợp.', ['relation' => 'qualifies', 'claim_id' => $claim->canonicalId])[0]->classification);
        self::assertSame('contradict', $planner->plan($subject, $profile, 'Có trường hợp khác.', ['relation' => 'contradicts', 'claim_id' => $claim->canonicalId])[0]->classification);
    }

    public function test_scope_mismatch_is_new_claim_and_ambiguous_or_unsupported_fails_closed(): void
    {
        $subject = UuidCodec::newV7(); $claim = new KnowledgeClaim(UuidCodec::newV7(), 'c', 'Cọc đen.', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'model']]);
        $planner = $this->planner([$claim]);
        self::assertSame('new_claim', $planner->plan($subject, new KnowledgeFacetProfile('recognition', 'variant'), 'Cọc đen.')[0]->classification);
        self::assertSame('ambiguous', $planner->plan($subject, new KnowledgeFacetProfile('recognition', 'variant'), 'Không rõ.', ['ambiguous' => true])[0]->classification);
        self::assertSame('unsupported', $planner->plan($subject, new KnowledgeFacetProfile('recognition', 'variant'), 'Quảng cáo.', ['unsupported' => true])[0]->classification);
    }

    public function test_fingerprint_changes_for_evidence_identity_revision_and_relation_but_not_order(): void
    {
        $subject = UuidCodec::newV7(); $source = UuidCodec::newV7(); $claim = new KnowledgeClaim(UuidCodec::newV7(), 'c', 'Claim', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'music', 'scope' => 'movement']]);
        $e1 = new Evidence(UuidCodec::newV7(), $claim->canonicalId, $source, 'supports', 'a', null, true, 1, ['visibility' => 'PUBLIC']);
        $e2 = new Evidence(UuidCodec::newV7(), $claim->canonicalId, $source, 'qualifies', 'b', null, true, 2, ['visibility' => 'PUBLIC']);
        $p1 = new CurrentTruthPacket($subject, new KnowledgeFacetProfile('music', 'movement'), [$claim], [], [], ['evidence' => [$e1]]);
        $p2 = new CurrentTruthPacket($subject, new KnowledgeFacetProfile('music', 'movement'), [$claim], [], [], ['evidence' => [$e2]]);
        $projector = new KnowledgeFragmentProjector();
        self::assertNotSame($projector->project($p1, 'music')->dependencyFingerprint, $projector->project($p2, 'music')->dependencyFingerprint);
        $p3 = new CurrentTruthPacket($subject, new KnowledgeFacetProfile('music', 'movement'), [$claim], [], [], ['evidence' => [$e1], 'sources' => [[$source, 1, true, true]]]);
        $p4 = new CurrentTruthPacket($subject, new KnowledgeFacetProfile('music', 'movement'), [$claim], [], [], ['evidence' => [$e1], 'sources' => [[$source, 1, true, true]]]);
        self::assertSame($projector->project($p3, 'music')->dependencyFingerprint, $projector->project($p4, 'music')->dependencyFingerprint);
        $e3 = new Evidence(UuidCodec::newV7(), $claim->canonicalId, $source, 'supports', 'z', null, true, 1, ['visibility' => 'PUBLIC']);
        $p5 = new CurrentTruthPacket($subject, new KnowledgeFacetProfile('music', 'movement'), [$claim], [], [], ['evidence' => [$e1, $e3]]);
        $p6 = new CurrentTruthPacket($subject, new KnowledgeFacetProfile('music', 'movement'), [$claim], [], [], ['evidence' => [$e3, $e1]]);
        self::assertSame($projector->project($p5, 'music')->dependencyFingerprint, $projector->project($p6, 'music')->dependencyFingerprint);
        $claimRevision = new KnowledgeClaim($claim->canonicalId, 'c', 'Claim', 'fact', $claim->provenance, true, 2);
        $p7 = new CurrentTruthPacket($subject, new KnowledgeFacetProfile('music', 'movement'), [$claimRevision], [], [], ['evidence' => [$e1]]);
        self::assertNotSame($projector->project($p3, 'music')->dependencyFingerprint, $projector->project($p7, 'music')->dependencyFingerprint);
    }

    public function test_medium_change_requires_stronger_verification(): void
    {
        $result = (new LivingKnowledgeSeoStabilityGuard())->evaluate(['url'=>'/x/','canonical'=>'/x/','h1'=>'X','title'=>'X','indexable'=>true,'description'=>'a'], ['url'=>'/x/','canonical'=>'/x/','h1'=>'X','title'=>'X','indexable'=>true,'description'=>'b','faq'=>'new']);
        self::assertSame('MEDIUM', $result['risk']); self::assertTrue($result['allowed']); self::assertTrue($result['stronger_verification_required']); self::assertSame(['description','faq'], $result['changed']);
    }

    private function planner(array $items): KnowledgeEnrichmentPlanner
    {
        $claims = new class($items) implements KnowledgeRepository { public function __construct(private array $items) {} public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; } public function findByStableKey(string $key): ?KnowledgeClaim { return null; } public function create(KnowledgeClaim $claim): KnowledgeClaim { throw new \LogicException(); } public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { throw new \LogicException(); } public function list(bool $includeRetired = false): array { return $this->items; } };
        $sources = new class implements SourceRepository { public function findByCanonicalId(string $id): ?Source { return null; } public function findByStableKey(string $key): ?Source { return null; } public function create(Source $s): Source { throw new \LogicException(); } public function update(Source $s, int $r): Source { throw new \LogicException(); } public function list(bool $i=false): array { return []; } };
        $evidence = new class implements EvidenceRepository { public function findByCanonicalId(string $id): ?Evidence { return null; } public function create(Evidence $e): Evidence { throw new \LogicException(); } public function update(Evidence $e, int $r): Evidence { throw new \LogicException(); } public function listByClaim(string $id, bool $i=false): array { return []; } public function listBySource(string $id, bool $i=false): array { return []; } };
        return new KnowledgeEnrichmentPlanner($claims, $evidence, $sources);
    }
}
