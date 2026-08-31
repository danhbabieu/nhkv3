<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\KnowledgePageQuery;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class KnowledgePageQueryTest extends TestCase
{
    public function test_public_query_returns_active_claims_and_hides_private_or_unlinked_evidence(): void
    {
        $claimId = UuidCodec::newV7(); $sourceId = UuidCodec::newV7(); $privateSourceId = UuidCodec::newV7();
        $claim = new KnowledgeClaim($claimId, 'nhk:knowledge:query-test', 'A bounded public claim.', 'fact');
        $claims = new class($claim) implements KnowledgeRepository {
            public function __construct(private KnowledgeClaim $claim) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return $id === $this->claim->canonicalId ? $this->claim : null; }
            public function findByStableKey(string $key): ?KnowledgeClaim { return $key === $this->claim->stableKey ? $this->claim : null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return [$this->claim]; }
        };
        $sources = new class($sourceId, $privateSourceId) implements SourceRepository {
            public function __construct(private string $activeId, private string $privateId) {}
            public function findByCanonicalId(string $id): ?Source { return $id === $this->activeId ? new Source($id, 'nhk:source:public', 'Public source', 'catalog', 'https://example.test/source') : ($id === $this->privateId ? new Source($id, 'nhk:source:private', 'Private source', 'catalog', null, ['visibility' => 'PRIVATE']) : null); }
            public function findByStableKey(string $key): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $evidence = new class($claimId, $sourceId, $privateSourceId) implements EvidenceRepository {
            public function __construct(private string $claimId, private string $activeSourceId, private string $privateSourceId) {}
            public function findByCanonicalId(string $id): ?Evidence { return null; }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return [new Evidence(UuidCodec::newV7(), $this->claimId, $this->activeSourceId, 'supports', 'Public excerpt.'), new Evidence(UuidCodec::newV7(), $this->claimId, $this->privateSourceId, 'supports', 'Private source excerpt.'), new Evidence(UuidCodec::newV7(), $this->claimId, $this->activeSourceId, 'supports', 'Private evidence excerpt.', null, true, 1, ['visibility' => 'PRIVATE']), new Evidence(UuidCodec::newV7(), $this->claimId, $this->activeSourceId, 'supports', 'Retired excerpt.', null, false)]; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $result = (new KnowledgePageQuery($claims, $evidence, $sources))->detail($claimId);
        self::assertNotNull($result); self::assertCount(1, $result['evidence']); self::assertSame('Public excerpt.', $result['evidence'][0]['excerpt']); self::assertSame('Public source', $result['evidence'][0]['source_title']); self::assertSame('catalog', $result['evidence'][0]['source_type']); self::assertSame('https://example.test/source', $result['evidence'][0]['source_locator']);
        self::assertSame($claimId, (new KnowledgePageQuery($claims, $evidence, $sources))->detail('nhk:knowledge:query-test')['id']);
    }

    public function test_inactive_claim_is_not_public(): void
    {
        $claim = new KnowledgeClaim(UuidCodec::newV7(), 'nhk:knowledge:retired-query-test', 'Retired claim.', 'fact', [], false);
        $claims = new class($claim) implements KnowledgeRepository {
            public function __construct(private KnowledgeClaim $claim) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return $this->claim; }
            public function findByStableKey(string $key): ?KnowledgeClaim { return $this->claim; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return [$this->claim]; }
        };
        $emptySources = new class implements SourceRepository { public function findByCanonicalId(string $id): ?Source { return null; } public function findByStableKey(string $key): ?Source { return null; } public function create(Source $source): Source { return $source; } public function update(Source $source, int $expectedRevision): Source { return $source; } public function list(bool $includeRetired = false): array { return []; } };
        $emptyEvidence = new class implements EvidenceRepository { public function findByCanonicalId(string $id): ?Evidence { return null; } public function create(Evidence $evidence): Evidence { return $evidence; } public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; } public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; } public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; } };
        self::assertNull((new KnowledgePageQuery($claims, $emptyEvidence, $emptySources))->detail($claim->canonicalId));
    }
}
