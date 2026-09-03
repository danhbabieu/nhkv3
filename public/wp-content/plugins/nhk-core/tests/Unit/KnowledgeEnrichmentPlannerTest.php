<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\KnowledgeEnrichmentPlanner;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source, KnowledgeFacetProfile};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class KnowledgeEnrichmentPlannerTest extends TestCase
{
    public function test_same_claim_is_classified_without_writing(): void
    {
        $subject = UuidCodec::newV7();
        $claim = new KnowledgeClaim(UuidCodec::newV7(), 'nhk:knowledge:odo-62-black', 'Odo 62 thường có cọc đen.', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant']]);
        $claims = new class($claim) implements KnowledgeRepository { public function __construct(private KnowledgeClaim $claim) {} public function findByCanonicalId(string $id): ?KnowledgeClaim { return $this->claim->canonicalId === $id ? $this->claim : null; } public function findByStableKey(string $key): ?KnowledgeClaim { return $this->claim->stableKey === $key ? $this->claim : null; } public function create(KnowledgeClaim $claim): KnowledgeClaim { throw new \LogicException('planner must not write'); } public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { throw new \LogicException('planner must not write'); } public function list(bool $includeRetired = false): array { return [$this->claim]; } };
        $emptyClaims = new class implements SourceRepository { public function findByCanonicalId(string $id): ?Source { return null; } public function findByStableKey(string $key): ?Source { return null; } public function create(Source $item): Source { throw new \LogicException('planner must not write'); } public function update(Source $item, int $revision): Source { throw new \LogicException('planner must not write'); } public function list(bool $includeRetired = false): array { return []; } };
        $emptyEvidence = new class implements EvidenceRepository { public function findByCanonicalId(string $id): ?Evidence { return null; } public function create(Evidence $item): Evidence { throw new \LogicException('planner must not write'); } public function update(Evidence $item, int $revision): Evidence { throw new \LogicException('planner must not write'); } public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; } public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; } };
        $result = (new KnowledgeEnrichmentPlanner($claims, $emptyEvidence, $emptyClaims))->plan($subject, new KnowledgeFacetProfile('recognition', 'variant'), 'Odo 62 thường có cọc đen.');
        self::assertSame('same_claim', $result[0]->classification);
        self::assertSame($claim->canonicalId, $result[0]->provenance['matched_claim_id']);
    }

    public function test_unresolved_source_is_review_candidate_not_evidence_candidate(): void
    {
        $subject = UuidCodec::newV7(); $claimId = UuidCodec::newV7();
        $emptyClaims = new class($claimId) implements KnowledgeRepository { public function __construct(private string $id) {} public function findByCanonicalId(string $id): ?KnowledgeClaim { return $id === $this->id ? new KnowledgeClaim($this->id, 'claim', 'Claim.', 'fact') : null; } public function findByStableKey(string $key): ?KnowledgeClaim { return null; } public function create(KnowledgeClaim $claim): KnowledgeClaim { throw new \LogicException('planner must not write'); } public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { throw new \LogicException('planner must not write'); } public function list(bool $includeRetired = false): array { return []; } };
        $emptySources = new class implements SourceRepository { public function findByCanonicalId(string $id): ?Source { return null; } public function findByStableKey(string $key): ?Source { return null; } public function create(Source $item): Source { throw new \LogicException('planner must not write'); } public function update(Source $item, int $revision): Source { throw new \LogicException('planner must not write'); } public function list(bool $includeRetired = false): array { return []; } };
        $emptyEvidence = new class implements EvidenceRepository { public function findByCanonicalId(string $id): ?Evidence { return null; } public function create(Evidence $item): Evidence { throw new \LogicException('planner must not write'); } public function update(Evidence $item, int $revision): Evidence { throw new \LogicException('planner must not write'); } public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; } public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; } };
        $candidate = (new KnowledgeEnrichmentPlanner($emptyClaims, $emptyEvidence, $emptySources))->plan($subject, new KnowledgeFacetProfile('recognition', 'variant'), 'Quan sát.', ['relation' => 'supports', 'claim_id' => $claimId, 'source_id' => '']);
        self::assertSame('ambiguous', $candidate[0]->classification); self::assertSame('evidence_review', $candidate[0]->provenance['candidate_kind']); self::assertArrayNotHasKey('source_id', $candidate[0]->provenance);
    }

    public function test_resolved_claim_and_source_bind_dependency_revisions(): void
    {
        $subject = UuidCodec::newV7(); $claimId = UuidCodec::newV7(); $sourceId = UuidCodec::newV7();
        $claim = new KnowledgeClaim($claimId, 'claim', 'Claim.', 'fact', [], true, 4); $source = new Source($sourceId, 'source', 'Source.', 'website', null, ['visibility' => 'PUBLIC'], true, 7);
        $claims = new class($claim) implements KnowledgeRepository { public function __construct(private KnowledgeClaim $item) {} public function findByCanonicalId(string $id): ?KnowledgeClaim { return $id === $this->item->canonicalId ? $this->item : null; } public function findByStableKey(string $key): ?KnowledgeClaim { return null; } public function create(KnowledgeClaim $claim): KnowledgeClaim { throw new \LogicException(); } public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { throw new \LogicException(); } public function list(bool $includeRetired = false): array { return []; } };
        $sources = new class($source) implements SourceRepository { public function __construct(private Source $item) {} public function findByCanonicalId(string $id): ?Source { return $id === $this->item->canonicalId ? $this->item : null; } public function findByStableKey(string $key): ?Source { return null; } public function create(Source $item): Source { throw new \LogicException(); } public function update(Source $item, int $revision): Source { throw new \LogicException(); } public function list(bool $includeRetired = false): array { return []; } };
        $evidence = new class implements EvidenceRepository { public function findByCanonicalId(string $id): ?Evidence { return null; } public function create(Evidence $item): Evidence { throw new \LogicException(); } public function update(Evidence $item, int $revision): Evidence { throw new \LogicException(); } public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; } public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; } };
        $candidate = (new KnowledgeEnrichmentPlanner($claims, $evidence, $sources))->plan($subject, new KnowledgeFacetProfile('recognition', 'variant'), 'Quan sát.', ['relation' => 'qualifies', 'claim_id' => $claimId, 'source_id' => $sourceId, 'locator' => 'p.4']);
        self::assertSame('qualify', $candidate[0]->classification); self::assertSame(4, $candidate[0]->provenance['claim_revision']); self::assertSame(7, $candidate[0]->provenance['source_revision']); self::assertSame('p.4', $candidate[0]->provenance['locator']);
    }
}
