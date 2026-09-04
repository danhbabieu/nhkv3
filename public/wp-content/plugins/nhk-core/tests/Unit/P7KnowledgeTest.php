<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Domain\Knowledge\DependencyValidationException;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class P7KnowledgeTest extends TestCase
{
    public function test_dependency_validation_accepts_active_private_evidence_without_public_visibility(): void
    {
        $claimId = UuidCodec::newV7(); $sourceId = UuidCodec::newV7(); $evidenceId = UuidCodec::newV7();
        $claim = new KnowledgeClaim($claimId, 'claim-key', 'Claim');
        $source = new Source($sourceId, 'source-key', 'Source', metadata: ['visibility' => 'PRIVATE']);
        $evidence = new Evidence($evidenceId, $claimId, $sourceId, excerpt: 'Excerpt', metadata: ['visibility' => 'HIDDEN']);
        $validator = new CanonicalDependencyValidator(
            new class($claim) implements KnowledgeRepository { public function __construct(private KnowledgeClaim $claim) {} public function findByCanonicalId(string $id): ?KnowledgeClaim { return $id === $this->claim->canonicalId ? $this->claim : null; } public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; } public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; } public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; } public function list(bool $includeRetired = false): array { return [$this->claim]; } },
            new class($source) implements SourceRepository { public function __construct(private Source $source) {} public function findByCanonicalId(string $id): ?Source { return $id === $this->source->canonicalId ? $this->source : null; } public function findByStableKey(string $stableKey): ?Source { return null; } public function create(Source $source): Source { return $source; } public function update(Source $source, int $expectedRevision): Source { return $source; } public function list(bool $includeRetired = false): array { return [$this->source]; } },
            new class($evidence) implements EvidenceRepository { public function __construct(private Evidence $evidence) {} public function findByCanonicalId(string $id): ?Evidence { return $id === $this->evidence->canonicalId ? $this->evidence : null; } public function create(Evidence $evidence): Evidence { return $evidence; } public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; } public function listByClaim(string $claimId, bool $includeRetired = false): array { return [$this->evidence]; } public function listBySource(string $sourceId, bool $includeRetired = false): array { return [$this->evidence]; } },
        );
        self::assertSame($evidenceId, $validator->evidence($evidenceId)->canonicalId);
    }

    public function test_dependency_validation_rejects_inactive_evidence(): void
    {
        $id = UuidCodec::newV7();
        $validator = new CanonicalDependencyValidator($this->createMock(KnowledgeRepository::class), $this->createMock(SourceRepository::class), new class($id) implements EvidenceRepository { public function __construct(private string $id) {} public function findByCanonicalId(string $id): ?Evidence { return $id === $this->id ? new Evidence($id, UuidCodec::newV7(), UuidCodec::newV7(), excerpt: 'x', active: false) : null; } public function create(Evidence $evidence): Evidence { return $evidence; } public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; } public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; } public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; } });
        $this->expectException(DependencyValidationException::class);
        $validator->evidence($id);
    }
    public function test_claim_source_and_evidence_are_atomic_and_idempotent_at_service_boundary(): void
    {
        $claims = new class implements KnowledgeRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return $this->items[$id] ?? null; }
            public function findByStableKey(string $key): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $this->items[$claim->canonicalId] = $claim; }
            public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { return $this->items[$claim->canonicalId] = $claim; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $sources = new class implements SourceRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Source { return $this->items[$id] ?? null; }
            public function findByStableKey(string $key): ?Source { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(Source $source): Source { return $this->items[$source->canonicalId] = $source; }
            public function update(Source $source, int $revision): Source { return $this->items[$source->canonicalId] = $source; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $evidence = new class implements EvidenceRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Evidence { return $this->items[$id] ?? null; }
            public function create(Evidence $item): Evidence { return $this->items[$item->canonicalId] = $item; }
            public function update(Evidence $item, int $revision): Evidence { return $this->items[$item->canonicalId] = $item; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return array_values(array_filter($this->items, fn (Evidence $item): bool => $item->claimId === $claimId)); }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return array_values(array_filter($this->items, fn (Evidence $item): bool => $item->sourceId === $sourceId)); }
        };
        $service = new KnowledgeService($claims, $sources, $evidence);
        $claim = $service->createClaim('odo-history-1', 'The clock was made in the early twentieth century.', 'history', ['origin' => 'catalog']);
        $source = $service->createSource('catalog-1', 'Archive catalogue', 'catalog', 'https://example.test/catalog/1');
        $sameClaim = $service->createClaim('odo-history-1', 'The clock was made in the early twentieth century.', 'history', ['origin' => 'catalog']);
        $citation = $service->cite($claim->canonicalId, $source->canonicalId, 'Early twentieth century', 'supports', null, ['visibility' => 'PUBLIC']);
        self::assertSame($claim->canonicalId, $sameClaim->canonicalId);
        self::assertSame($claim->canonicalId, $citation->claimId);
        self::assertSame(['visibility' => 'PUBLIC'], $citation->metadata);
        self::assertCount(1, $service->evidenceForClaim($claim->canonicalId));

        $claim = $service->updateClaim($claim->canonicalId, 'The clock was made around 1905.', 'history', ['origin' => 'catalog', 'reviewed' => true], 1);
        $source = $service->updateSource($source->canonicalId, 'Reviewed archive catalogue', 'catalog', 'https://example.test/catalog/1', ['reviewed' => true], 1);
        $citation = $service->updateEvidence($citation->canonicalId, 'qualifies', 'Circa 1905', 'https://example.test/catalog/1#date', ['reviewed' => true], 1);
        self::assertSame('The clock was made around 1905.', $claim->claimText);
        self::assertSame('Reviewed archive catalogue', $source->title);
        self::assertSame('qualifies', $citation->relation);
        self::assertFalse($service->retireClaim($claim->canonicalId, 1)->active);
        self::assertTrue($service->reactivateClaim($claim->canonicalId, 1)->active);
        self::assertFalse($service->retireSource($source->canonicalId, 1)->active);
        self::assertFalse($service->retireEvidence($citation->canonicalId, 1)->active);
    }

    public function test_evidence_requires_existing_claim_and_source(): void
    {
        $emptyClaims = new class implements KnowledgeRepository {
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; }
            public function findByStableKey(string $key): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $emptySources = new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return null; }
            public function findByStableKey(string $key): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $revision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $evidence = new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?Evidence { return null; }
            public function create(Evidence $item): Evidence { return $item; }
            public function update(Evidence $item, int $revision): Evidence { return $item; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $this->expectException(\NHK\Core\Domain\Knowledge\KnowledgeException::class);
        (new KnowledgeService($emptyClaims, $emptySources, $evidence))->cite(UuidCodec::newV7(), UuidCodec::newV7(), 'orphan evidence');
    }

    public function test_claim_public_readiness_hides_explicitly_unverified_imports(): void
    {
        self::assertTrue((new KnowledgeClaim(UuidCodec::newV7(), 'nhk:knowledge:public-ready', 'Public-ready claim.'))->isPublic());
        self::assertTrue((new KnowledgeClaim(UuidCodec::newV7(), 'nhk:knowledge:verified', 'Verified claim.', 'fact', ['metadata' => ['verification_status' => 'VERIFIED']]))->isPublic());
        self::assertFalse((new KnowledgeClaim(UuidCodec::newV7(), 'nhk:knowledge:unverified', 'Unverified claim.', 'fact', ['metadata' => ['verification_status' => 'UNVERIFIED']]))->isPublic());
        self::assertFalse((new KnowledgeClaim(UuidCodec::newV7(), 'nhk:knowledge:needs-confirmation', 'Needs confirmation claim.', 'fact', ['metadata' => ['knowledge_status' => 'NEEDS_CONFIRMATION']]))->isPublic());
    }

    public function test_source_and_evidence_default_to_private_until_explicitly_published(): void
    {
        $sourceId = UuidCodec::newV7();
        $claimId = UuidCodec::newV7();
        self::assertFalse((new Source($sourceId, 'nhk:source:private-by-default', 'Private by default'))->isPublic());
        self::assertFalse((new Evidence(UuidCodec::newV7(), $claimId, $sourceId, 'supports', 'Private by default'))->isPublic());
        self::assertTrue((new Source($sourceId, 'nhk:source:explicit-public', 'Explicit public', 'website', null, ['visibility' => 'PUBLIC']))->isPublic());
        self::assertTrue((new Evidence(UuidCodec::newV7(), $claimId, $sourceId, 'supports', 'Explicit public', null, true, 1, ['visibility' => 'PUBLIC']))->isPublic());
    }
}
