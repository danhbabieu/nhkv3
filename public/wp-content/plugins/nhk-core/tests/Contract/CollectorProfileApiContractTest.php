<?php
declare(strict_types=1);

namespace NHK\Tests\Contract;

use NHK\Core\Application\Collector\CollectorProfileQuery;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Infrastructure\Http\CollectorProfileApi;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class CollectorProfileApiContractTest extends TestCase
{
    /** @var list<string> */
    private const FACETS = [
        'display_form', 'dimensions', 'dating', 'case_styles', 'motifs',
        'materials', 'craft_modes', 'production_scale', 'movement_family',
        'running_duration', 'drive_system', 'functions', 'sound', 'music',
        'automata', 'night_shutoff', 'condition_guidance', 'originality_guidance',
        'provenance', 'rarity', 'origin_certification',
    ];

    public function test_available_response_has_the_collector_profile_shape_and_branch_ids(): void
    {
        $subjectId = UuidCodec::newV7();
        $partialClaimId = UuidCodec::newV7();
        $unresolvedClaimId = UuidCodec::newV7();
        $verifiedClaimId = UuidCodec::newV7();
        $sourceId = UuidCodec::newV7();
        $authority = $this->authority($subjectId);
        $claims = $this->claims([
            new KnowledgeClaim($partialClaimId, 'nhk:knowledge:collector-contract', 'Một ngày', 'fact', [
                'metadata' => ['subject_id' => $subjectId, 'collector_facet' => 'running_duration', 'scope' => 'entity'],
            ]),
            new KnowledgeClaim($unresolvedClaimId, 'nhk:knowledge:unresolved-contract', 'Cần xác minh', 'fact', [
                'metadata' => ['subject_id' => $subjectId, 'collector_facet' => 'unsupported-facet', 'scope' => 'entity'],
            ]),
            new KnowledgeClaim($verifiedClaimId, 'nhk:knowledge:verified-contract', 'Tường', 'fact', [
                'metadata' => ['subject_id' => $subjectId, 'collector_facet' => 'display_form', 'scope' => 'entity'],
            ]),
        ]);
        $evidence = $this->createMock(EvidenceRepository::class);
        $evidence->method('listByClaim')->willReturnCallback(static fn (string $id): array => $id === $verifiedClaimId
            ? [new Evidence(UuidCodec::newV7(), $verifiedClaimId, $sourceId, 'supports', 'Supported contract evidence.', 'https://example.test/source', true, 1, ['visibility' => 'PUBLIC'])]
            : []);
        $sources = $this->createMock(SourceRepository::class);
        $sources->method('findByCanonicalId')->willReturn(new Source($sourceId, 'nhk:source:collector-contract', 'Collector contract source', 'catalog', 'https://example.test/source', ['visibility' => 'PUBLIC']));
        $related = static fn (string $id, array $filters): array => [
            'status' => 'available',
            'scope' => 'subject',
            'branch_scoped' => true,
            'media' => [['uuid' => 'media-branch-1']],
            'videos' => [['uuid' => 'video-branch-1']],
            'articles' => [['uuid' => 'article-branch-1']],
            'makers' => [['uuid' => 'brand-branch-1']],
        ];

        $response = (new CollectorProfileApi(new CollectorProfileQuery($authority, $claims, $evidence, $sources, $related)))->read($subjectId, 1, 50, 0);

        self::assertIsArray($response);
        self::assertSame('available', $response['status']);
        self::assertSame(['type', 'uuid', 'stable_key', 'name'], array_keys($response['subject']));
        self::assertSame($subjectId, $response['subject']['uuid']);
        self::assertSame(self::FACETS, array_keys($response['facets']));
        self::assertIsArray($response['coverage']);
        self::assertArrayHasKey('complete', $response['coverage']);
        self::assertArrayHasKey('truncated', $response['coverage']);
        self::assertArrayHasKey('knowledge_count', $response['coverage']);
        self::assertArrayHasKey('knowledge_page', $response['coverage']);
        self::assertArrayHasKey('knowledge_per_page', $response['coverage']);
        self::assertArrayHasKey('knowledge_has_next_page', $response['coverage']);
        $statuses = array_values(array_unique(array_column($response['knowledge'], 'status')));
        self::assertContains('verified', $statuses);
        self::assertContains('partial', $statuses);
        self::assertContains('unresolved', $statuses);
        self::assertSame([], array_diff($statuses, ['verified', 'partial', 'unresolved']));
        self::assertSame(['article-branch-1'], array_column($response['related_articles'], 'uuid'));
        self::assertSame(['media-branch-1'], array_column($response['media'], 'uuid'));
        self::assertSame(['video-branch-1'], array_column($response['videos'], 'uuid'));
        self::assertSame(['brand-branch-1'], array_column($response['makers'], 'uuid'));
        self::assertArrayNotHasKey('brands', $response['facets']);
    }

    public function test_unsupported_related_scope_fails_closed_instead_of_broadening(): void
    {
        $subjectId = UuidCodec::newV7();
        $related = static fn (string $id, array $filters): array => [
            'status' => 'available',
            'scope' => 'global',
            'items' => [['uuid' => 'unrelated-media']],
        ];

        $response = (new CollectorProfileApi(new CollectorProfileQuery(
            $this->authority($subjectId),
            $this->claims([]),
            $this->emptyEvidence(),
            $this->emptySources(),
            $related,
        )))->read($subjectId);

        self::assertSame('unavailable', $response['status']);
        self::assertSame('BRANCH_FILTER_UNSUPPORTED', $response['reason']);
        self::assertSame([], $response['media']);
    }

    public function test_missing_classification_is_stable_fail_closed_response(): void
    {
        $response = (new CollectorProfileApi(new CollectorProfileQuery(
            new InMemoryAuthorityRepository(),
            $this->claims([]),
            $this->emptyEvidence(),
            $this->emptySources(),
        )))->read(UuidCodec::newV7());

        self::assertSame('unavailable', $response['status']);
        self::assertSame('CLASSIFICATION_NOT_AVAILABLE', $response['reason']);
        self::assertSame([], $response['knowledge']);
        self::assertSame([], $response['media']);
        self::assertSame([], $response['videos']);
        self::assertSame([], $response['makers']);
    }

    public function test_permission_boundary_is_fail_closed_without_wordpress_capability_context(): void
    {
        self::assertFalse(CollectorProfileApi::permission());
    }

    private function authority(string $subjectId): InMemoryAuthorityRepository
    {
        $authority = new InMemoryAuthorityRepository();
        $authority->create(new AuthorityEntity($subjectId, 'classification', 'nhk:classification:clock-type.cuckoo-clock', 'Đồng hồ chim cúc cu', 1, []));
        return $authority;
    }

    private function claims(array $items): KnowledgeRepository
    {
        return new class($items) implements KnowledgeRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $stableKey) return $item; return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }

    private function emptyEvidence(): EvidenceRepository
    {
        return $this->createMock(EvidenceRepository::class);
    }

    private function emptySources(): SourceRepository
    {
        return $this->createMock(SourceRepository::class);
    }
}
