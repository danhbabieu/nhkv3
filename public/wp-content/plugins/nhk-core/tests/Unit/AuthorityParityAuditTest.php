<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Authority\AuthorityParityAudit;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use PHPUnit\Framework\TestCase;

final class AuthorityParityAuditTest extends TestCase
{
    public function test_audit_is_registry_driven_and_accepts_legitimate_empty_types(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new class implements AuthorityRepository {
            public function findByCanonicalId(string $id): ?AuthorityEntity { return null; }
            public function findByStableKey(string $type, string $key): ?AuthorityEntity { return null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
            public function listByType(string $type, bool $includeRetired = false): array
            {
                if ($type === 'brand') return [new AuthorityEntity('018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f11', 'brand', 'brand-one', 'Brand One', 1, [])];
                if ($type === 'music') return [new AuthorityEntity('018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f12', 'music', 'music-one', 'Music One', 1, [])];
                return [];
            }
        };

        $rows = (new AuthorityParityAudit(static fn (string $type): int => in_array($type, ['brand', 'music'], true) ? 1 : 0))->run($types, $repository);

        self::assertCount(9, $rows);
        self::assertSame(['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'], array_column($rows, 'type'));
        self::assertSame('OK', $rows[0]['status']);
        self::assertSame('EMPTY_VALID', $rows[2]['status']);
        self::assertSame(1, $rows[0]['physical_rows']);
        self::assertSame(1, $rows[0]['hydrated_rows']);
        self::assertSame(1, $rows[0]['query_rows']);
    }
}
