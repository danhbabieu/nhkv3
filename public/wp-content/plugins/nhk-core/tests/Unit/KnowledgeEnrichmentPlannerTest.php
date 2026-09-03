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
}
