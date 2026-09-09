<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Collector\CollectorProfileQuery;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CollectorProfileQueryTest extends TestCase
{
    public function test_branch_knowledge_is_paginated_and_does_not_leak_other_branch(): void
    {
        $subjectId = UuidCodec::newV7();
        $otherSubjectId = UuidCodec::newV7();
        [$authority, $claims] = $this->repositories($subjectId, $otherSubjectId, 55);

        $query = new CollectorProfileQuery($authority, $claims, $this->emptyEvidence(), $this->emptySources());
        $first = $query->build($subjectId, 1, 50);
        $second = $query->build($subjectId, 2, 50);

        self::assertSame('available', $first['status']);
        self::assertSame(55, $first['coverage']['knowledge_count']);
        self::assertSame(50, $first['coverage']['knowledge_returned']);
        self::assertTrue($first['coverage']['knowledge_has_next_page']);
        self::assertSame(5, $second['coverage']['knowledge_returned']);
        self::assertFalse($second['coverage']['knowledge_has_next_page']);
        self::assertNotContains('Unrelated branch claim', array_column($first['knowledge'], 'text'));
        self::assertNotContains('Unrelated branch claim', array_column($second['knowledge'], 'text'));
    }

    public function test_duplicate_relation_pages_are_deduplicated_by_canonical_uuid(): void
    {
        $subjectId = UuidCodec::newV7();
        [$authority, $claims] = $this->repositories($subjectId, UuidCodec::newV7(), 2);
        $items = $claims->list();
        $duplicateClaims = new class(array_merge($items, [$items[0], $items[1]])) implements KnowledgeRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $stableKey) return $item; return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };

        $profile = (new CollectorProfileQuery($authority, $duplicateClaims, $this->emptyEvidence(), $this->emptySources()))->build($subjectId, 1, 50);

        self::assertSame(2, $profile['coverage']['knowledge_count']);
        self::assertCount(2, $profile['knowledge']);
        self::assertSame(2, count(array_unique(array_column($profile['knowledge'], 'uuid'))));
    }

    public function test_render_cap_is_explicitly_truncated_without_claiming_complete_coverage(): void
    {
        $subjectId = UuidCodec::newV7();
        [$authority, $claims] = $this->repositories($subjectId, UuidCodec::newV7(), 55);

        $profile = (new CollectorProfileQuery($authority, $claims, $this->emptyEvidence(), $this->emptySources()))->build($subjectId, 1, 50, 20);

        self::assertTrue($profile['coverage']['truncated']);
        self::assertFalse($profile['coverage']['complete']);
        self::assertSame(55, $profile['coverage']['knowledge_count']);
        self::assertSame(20, $profile['coverage']['knowledge_returned']);
    }

    public function test_global_related_inventory_is_rejected_instead_of_broadening_branch_scope(): void
    {
        $subjectId = UuidCodec::newV7();
        [$authority, $claims] = $this->repositories($subjectId, UuidCodec::newV7(), 1);

        $query = new CollectorProfileQuery(
            $authority,
            $claims,
            $this->emptyEvidence(),
            $this->emptySources(),
            static fn (string $id, array $filters): array => [
                'status' => 'available',
                'scope' => 'global',
                'items' => [['type' => 'media', 'id' => 'unrelated-media']],
            ],
        );

        $profile = $query->build($subjectId);

        self::assertSame('unavailable', $profile['status']);
        self::assertSame('BRANCH_FILTER_UNSUPPORTED', $profile['reason']);
        self::assertSame([], $profile['media']);
    }

    /** @return array{0:InMemoryAuthorityRepository,1:KnowledgeRepository} */
    private function repositories(string $subjectId, string $otherSubjectId, int $count): array
    {
        $authority = new InMemoryAuthorityRepository();
        $authority->create(new AuthorityEntity($subjectId, 'classification', 'nhk:classification:clock-type.cuckoo-clock', 'Đồng hồ chim cúc cu', 1, []));
        $claims = [];
        for ($index = 1; $index <= $count; $index++) {
            $claims[] = new KnowledgeClaim(UuidCodec::newV7(), 'nhk:knowledge:cuckoo-' . $index, 'Branch claim ' . $index, 'fact', [
                'metadata' => ['subject_id' => $subjectId, 'facet' => 'configuration', 'scope' => 'entity', 'collector_facet' => 'running_duration'],
            ]);
        }
        $claims[] = new KnowledgeClaim(UuidCodec::newV7(), 'nhk:knowledge:other-branch', 'Unrelated branch claim', 'fact', [
            'metadata' => ['subject_id' => $otherSubjectId, 'facet' => 'configuration', 'scope' => 'entity', 'collector_facet' => 'running_duration'],
        ]);
        $repository = new class($claims) implements KnowledgeRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $stableKey) return $item; return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
        return [$authority, $repository];
    }

    private function emptyEvidence(): EvidenceRepository
    {
        return new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?Evidence { return null; }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
    }

    private function emptySources(): SourceRepository
    {
        return new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return null; }
            public function findByStableKey(string $stableKey): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
    }
}
