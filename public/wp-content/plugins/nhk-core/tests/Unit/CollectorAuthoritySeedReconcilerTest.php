<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Collector\CollectorAuthoritySeedReconciler;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use PHPUnit\Framework\TestCase;

final class CollectorAuthoritySeedReconcilerTest extends TestCase
{
    public function test_exact_match_is_reused_and_missing_candidates_are_not_created(): void
    {
        $existing = new AuthorityEntity('11111111-1111-4111-8111-111111111111', 'classification', 'nhk:classification:case-style.chalet', 'Chalet', 1, []);
        $authority = new class($existing) implements AuthorityRepository {
            public function __construct(private AuthorityEntity $existing) {}
            public function findByCanonicalId(string $id): ?AuthorityEntity { return $id === $this->existing->canonicalId ? $this->existing : null; }
            public function findByStableKey(string $type, string $stableKey): ?AuthorityEntity { return $stableKey === $this->existing->stableKey ? $this->existing : null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { throw new \LogicException('read-only reconciler'); }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { throw new \LogicException('read-only reconciler'); }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { throw new \LogicException('read-only reconciler'); }
            public function listByType(string $type, bool $includeRetired = false): array { return $type === 'classification' ? [$this->existing] : []; }
        };
        $rows = (new CollectorAuthoritySeedReconciler($authority))->reconcile();
        self::assertSame('REUSE', $rows[0]['status']);
        self::assertSame('NO_MATCH_CREATE_REQUIRES_EVIDENCE', $rows[1]['status']);
        self::assertArrayNotHasKey('name', $rows[1]);
    }
}
