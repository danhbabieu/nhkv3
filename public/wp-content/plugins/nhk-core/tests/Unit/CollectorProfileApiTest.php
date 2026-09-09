<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Collector\CollectorProfileQuery;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Infrastructure\Http\CollectorProfileApi;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CollectorProfileApiTest extends TestCase
{
    public function test_read_contract_contains_subject_coverage_facets_and_related_arrays(): void
    {
        $id = UuidCodec::newV7();
        $authority = new InMemoryAuthorityRepository();
        $authority->create(new AuthorityEntity($id, 'classification', 'nhk:classification:clock-type.cuckoo-clock', 'Đồng hồ chim cúc cu', 1, []));
        $claims = new class($id) implements KnowledgeRepository {
            public function __construct(private string $subject) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return [new KnowledgeClaim(UuidCodec::newV7(), 'nhk:knowledge:one-day', 'Một ngày', 'fact', ['metadata' => ['subject_id' => $this->subject, 'facet' => 'configuration', 'scope' => 'entity', 'collector_facet' => 'running_duration']])]; }
        };
        $emptyEvidence = new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?Evidence { return null; }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $emptySources = new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return null; }
            public function findByStableKey(string $stableKey): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };

        $response = (new CollectorProfileApi(new CollectorProfileQuery($authority, $claims, $emptyEvidence, $emptySources)))->read($id);

        self::assertIsArray($response);
        self::assertSame('available', $response['status']);
        self::assertSame(['type', 'uuid', 'stable_key', 'name'], array_keys($response['subject']));
        self::assertArrayHasKey('coverage', $response);
        self::assertArrayHasKey('facets', $response);
        self::assertArrayHasKey('related_articles', $response);
        self::assertArrayHasKey('media', $response);
        self::assertArrayHasKey('videos', $response);
        self::assertArrayHasKey('makers', $response);
    }

    public function test_read_contract_limits_pagination_arguments(): void
    {
        self::assertSame(200, CollectorProfileApi::args()['per_page']['maximum']);
        self::assertSame(10000, CollectorProfileApi::args()['render_cap']['maximum']);
    }
}
