<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Collector\CollectorCoverageAudit;
use NHK\Core\Application\Collector\CollectorProfileQuery;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Authority\AuthorityEntity;
use PHPUnit\Framework\TestCase;

final class CollectorCoverageAuditTest extends TestCase
{
    public function test_audit_reports_branch_coverage_without_mutation_or_semantic_repair(): void
    {
        $classification = new AuthorityEntity('11111111-1111-4111-8111-111111111111', 'classification', 'clock', 'Clock', 1, []);
        $authority = new class($classification) implements AuthorityRepository {
            public function __construct(private AuthorityEntity $entity) {}
            public function findByCanonicalId(string $id): ?AuthorityEntity { return $id === $this->entity->canonicalId ? $this->entity : null; }
            public function findByStableKey(string $type, string $stableKey): ?AuthorityEntity { return null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
            public function listByType(string $type, bool $includeInactive = false): array { return $type === 'classification' ? [$this->entity] : []; }
        };
        $profile = new CollectorProfileQuery($authority, $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class), $this->createMock(SourceRepository::class));
        $report = (new CollectorCoverageAudit($authority, $profile))->run();
        self::assertSame(1, $report['summary']['classification_count']);
        self::assertSame('COMPLETE', $report['items'][0]['status']);
        self::assertArrayHasKey('facet_counts', $report['items'][0]);
    }
}
