<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\PublicIdentity\PublicIdentityService;
use PHPUnit\Framework\TestCase;

final class PublicIdentityCanonicalAllocationTest extends TestCase
{
    private const OWNER_ID = '01a06815-1e51-7964-b004-1ba79e488ad1';

    public function test_canonical_allocation_uses_shortest_clean_slug_when_free(): void
    {
        $repository = new CanonicalAllocationRepository([]);
        $service = new PublicIdentityService($repository, static fn(string $slug): bool => false);

        $result = $service->allocateCanonical('authority', self::OWNER_ID, 'brand', 'root', 'Nhà kho người sưu tập', ['1978'], 'allocate-1');

        self::assertSame('nha-kho-nguoi-suu-tap', $result['current_slug']);
        self::assertSame(self::OWNER_ID, $result['owner_id']);
    }

    public function test_canonical_allocation_adds_meaningful_suffix_only_after_real_collision(): void
    {
        $repository = new CanonicalAllocationRepository(['may-36' => true]);
        $service = new PublicIdentityService($repository, static fn(string $slug): bool => false);

        $result = $service->allocateCanonical('authority', self::OWNER_ID, 'movement', 'movement', 'Máy 36', ['1962', '10-con'], 'allocate-2');

        self::assertSame('may-36-1962', $result['current_slug']);
        self::assertSame('/bo-may/may-36-1962/', $result['current_path']);
    }

    public function test_canonical_allocation_does_not_hide_unresolved_collision_with_timestamp_or_internal_id(): void
    {
        $repository = new CanonicalAllocationRepository(['may-36' => true, 'may-36-1962' => true]);
        $service = new PublicIdentityService($repository, static fn(string $slug): bool => false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PUBLIC_SLUG_COLLISION_REQUIRES_RECONCILIATION');
        $service->allocateCanonical('authority', self::OWNER_ID, 'movement', 'movement', 'Máy 36', ['1962'], 'allocate-3');
    }
}

final class CanonicalAllocationRepository
{
    public function __construct(private array $taken) {}

    public function slugExists(string $routeType, string $scope, string $slug, ?string $excludeIdentityId = null): bool
    {
        return isset($this->taken[$slug]);
    }

    public function allocate(array $record, string $key): array
    {
        $record['identity_id'] = 'public-identity-1';
        $record['revision'] = 1;
        return $record;
    }

    public function findCurrentById(string $identityId): ?array { return null; }
    public function change(array $record, string $oldPath, int $expectedRevision, string $key): array { return $record; }
    public function resolveHistoric(string $path): array { return ['status' => 'NOT_FOUND']; }
}
