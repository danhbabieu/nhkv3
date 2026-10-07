<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\{KnowledgeEnrichmentPlanner, KnowledgeService};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, KnowledgeFacetProfile, Source};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class SystemWideDuplicatePreventionTest extends TestCase
{
    public function test_different_claim_request_keys_reuse_same_scoped_identity(): void
    {
        [$claims, $sources, $evidence] = $this->repositories();
        $service = new KnowledgeService($claims, $sources, $evidence);
        $subject = UuidCodec::newV7();
        $first = $service->createClaim('knowledge:first-key', 'Một fact nguyên tử.', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant']]);
        $second = $service->createClaim('knowledge:second-key', 'Một fact nguyên tử.', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant']]);

        self::assertSame($first->canonicalId, $second->canonicalId);
        self::assertCount(1, $claims->items);
    }

    public function test_different_video_referents_do_not_reuse_deictic_provenance_claim(): void
    {
        [$claims, $sources, $evidence] = $this->repositories();
        $service = new KnowledgeService($claims, $sources, $evidence);
        $subject = UuidCodec::newV7();
        $provenance = static fn (string $videoId): array => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'entity', 'platform' => 'youtube', 'external_video_id' => $videoId]];
        $first = $service->createClaim('video:first', 'The source identifies this Video as concerning canonical Odo 62.', 'provenance', $provenance('video-a'));
        $second = $service->createClaim('video:second', 'The source identifies this Video as concerning canonical Odo 62.', 'provenance', $provenance('video-b'));

        self::assertNotSame($first->canonicalId, $second->canonicalId);
        self::assertCount(2, $claims->items);
    }

    public function test_same_video_referent_reuses_claim_across_sources(): void
    {
        [$claims, $sources, $evidence] = $this->repositories();
        $service = new KnowledgeService($claims, $sources, $evidence);
        $subject = UuidCodec::newV7();
        $provenance = static fn (string $sourceKey): array => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'entity', 'platform' => 'youtube', 'external_video_id' => 'video-a', 'source_stable_key' => $sourceKey]];
        $first = $service->createClaim('video:source-a', 'The source identifies this Video as concerning canonical Odo 62.', 'provenance', $provenance('source-a'));
        $second = $service->createClaim('video:source-b', 'The source identifies this Video as concerning canonical Odo 62.', 'provenance', $provenance('source-b'));

        self::assertSame($first->canonicalId, $second->canonicalId);
        self::assertCount(1, $claims->items);
    }

    public function test_possible_same_scope_paraphrase_is_review_not_create(): void
    {
        [$claims, $sources, $evidence] = $this->repositories();
        $subject = UuidCodec::newV7();
        $claims->create(new KnowledgeClaim(UuidCodec::newV7(), 'knowledge:existing', 'Mặt số có cọc đen.', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant']]));
        $planner = new KnowledgeEnrichmentPlanner($claims, $evidence, $sources);
        $candidate = $planner->plan($subject, new KnowledgeFacetProfile('recognition', 'variant'), 'Mặt số dùng cọc màu đen.')[0];

        self::assertSame('ambiguous', $candidate->classification);
        self::assertSame('POSSIBLE_EQUIVALENT_CLAIM', $candidate->provenance['reason']);
    }

    public function test_clearly_different_same_scope_fact_remains_new_claim(): void
    {
        [$claims, $sources, $evidence] = $this->repositories();
        $subject = UuidCodec::newV7();
        $claims->create(new KnowledgeClaim(UuidCodec::newV7(), 'knowledge:existing', 'Mặt số có cọc đen.', 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'recognition', 'scope' => 'variant']]));
        $planner = new KnowledgeEnrichmentPlanner($claims, $evidence, $sources);
        $candidate = $planner->plan($subject, new KnowledgeFacetProfile('recognition', 'variant'), 'Bộ máy dùng lò xo.')[0];

        self::assertSame('new_claim', $candidate->classification);
    }

    public function test_source_locator_and_evidence_support_identity_reuse_across_keys(): void
    {
        [$claims, $sources, $evidence] = $this->repositories();
        $service = new KnowledgeService($claims, $sources, $evidence);
        $claim = $service->createClaim('knowledge:evidence-claim', 'Claim with support.', 'fact', ['metadata' => ['subject_id' => UuidCodec::newV7(), 'facet' => 'recognition', 'scope' => 'variant']]);
        $source = $service->createSource('source:first-key', 'Catalogue', 'catalog', 'https://example.test/item/?b=2&a=1');
        $sameSource = $service->createSource('source:second-key', 'Same catalogue', 'catalog', 'https://EXAMPLE.test/item?a=1&b=2');
        $first = $service->citeWithId(UuidCodec::newV7(), $claim->canonicalId, $source->canonicalId, 'Support passage.', 'supports', 'https://example.test/item#p4', ['visibility' => 'PRIVATE']);
        $second = $service->citeWithId(UuidCodec::newV7(), $claim->canonicalId, $sameSource->canonicalId, 'Support passage.', 'supports', 'https://example.test/item#p4', ['visibility' => 'PRIVATE']);

        self::assertSame($source->canonicalId, $sameSource->canonicalId);
        self::assertSame($first->canonicalId, $second->canonicalId);
        self::assertCount(1, $evidence->items);
    }

    /** @return array{0:InMemoryClaimRepository,1:InMemorySourceRepository,2:InMemoryEvidenceRepository} */
    private function repositories(): array
    {
        return [new InMemoryClaimRepository(), new InMemorySourceRepository(), new InMemoryEvidenceRepository()];
    }
}

final class InMemoryClaimRepository implements KnowledgeRepository
{
    /** @var array<string,KnowledgeClaim> */ public array $items = [];
    public function findByCanonicalId(string $id): ?KnowledgeClaim { return $this->items[$id] ?? null; }
    public function findByStableKey(string $key): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
    public function create(KnowledgeClaim $claim): KnowledgeClaim { return $this->items[$claim->canonicalId] = $claim; }
    public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $this->items[$claim->canonicalId] = $claim; }
    public function list(bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (KnowledgeClaim $claim): bool => $includeRetired || $claim->active)); }
}

final class InMemorySourceRepository implements SourceRepository
{
    /** @var array<string,Source> */ public array $items = [];
    public function findByCanonicalId(string $id): ?Source { return $this->items[$id] ?? null; }
    public function findByStableKey(string $key): ?Source { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
    public function create(Source $source): Source { return $this->items[$source->canonicalId] = $source; }
    public function update(Source $source, int $expectedRevision): Source { return $this->items[$source->canonicalId] = $source; }
    public function list(bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (Source $source): bool => $includeRetired || $source->active)); }
}

final class InMemoryEvidenceRepository implements EvidenceRepository
{
    /** @var array<string,Evidence> */ public array $items = [];
    public function findByCanonicalId(string $id): ?Evidence { return $this->items[$id] ?? null; }
    public function create(Evidence $evidence): Evidence { return $this->items[$evidence->canonicalId] = $evidence; }
    public function update(Evidence $evidence, int $expectedRevision): Evidence { return $this->items[$evidence->canonicalId] = $evidence; }
    public function listByClaim(string $claimId, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (Evidence $evidence): bool => $evidence->claimId === $claimId && ($includeRetired || $evidence->active))); }
    public function listBySource(string $sourceId, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (Evidence $evidence): bool => $evidence->sourceId === $sourceId && ($includeRetired || $evidence->active))); }
}
