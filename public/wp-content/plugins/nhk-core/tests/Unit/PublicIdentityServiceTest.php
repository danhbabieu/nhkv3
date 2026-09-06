<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\PublicIdentity\PublicIdentityService;
use PHPUnit\Framework\TestCase;

final class PublicIdentityServiceTest extends TestCase
{
    public function test_explicit_slug_change_is_cas_bound_and_keeps_append_only_history(): void
    {
        $repository = new FakeIdentityRepository();
        $service = new PublicIdentityService($repository, static fn (string $slug): bool => false);

        $first = $service->allocate('authority', '01a06815-1e51-7964-b004-1ba79e488ad1', 'brand', 'root', 'odo', 'request-1');
        $changed = $service->changeSlug($first['identity_id'], 'odo-moi', 1, 'request-2');
        $replayed = $service->changeSlug($first['identity_id'], 'odo-moi', 2, 'request-2');

        self::assertSame('/odo-moi/', $changed['current_path']);
        self::assertSame($changed, $replayed);
        self::assertSame(2, $repository->current()['revision']);
        self::assertSame(['/odo/'], $repository->historicPaths());
    }

    public function test_public_identity_uses_shared_slug_policy_without_changing_owner_identity(): void
    {
        $repository = new FakeIdentityRepository();
        $service = new PublicIdentityService($repository, static fn (string $slug): bool => false);
        $ownerId = '01a06815-1e51-7964-b004-1ba79e488ad1';

        $identity = $service->allocate('authority', $ownerId, 'brand', 'root', 'Tri thức NHK tuổi ở xưởng', 'request-shared-slug');

        self::assertSame($ownerId, $identity['owner_id']);
        self::assertSame('tri-thuc-nha-kho-tuoi-o-xuong', $identity['current_slug']);
        self::assertSame('/tri-thuc-nha-kho-tuoi-o-xuong/', $identity['current_path']);
        self::assertStringNotContainsString($ownerId, $identity['current_path']);
    }

    public function test_stale_revision_and_native_collision_fail_closed(): void
    {
        $repository = new FakeIdentityRepository();
        $service = new PublicIdentityService($repository, static fn (string $slug): bool => $slug === 'native');
        $identity = $service->allocate('authority', '01a06815-1e51-7964-b004-1ba79e488ad1', 'brand', 'root', 'odo', 'request-1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STALE_REVISION');
        $service->changeSlug($identity['identity_id'], 'native', 99, 'request-3');
    }

    public function test_native_collision_and_invalid_input_fail_closed(): void
    {
        $repository = new FakeIdentityRepository();
        $service = new PublicIdentityService($repository, static fn (string $slug): bool => $slug === 'native');
        $identity = $service->allocate('authority', '01a06815-1e51-7964-b004-1ba79e488ad1', 'brand', 'root', 'odo', 'request-1');
        try {
            $service->changeSlug($identity['identity_id'], 'native', 1, 'request-3');
            self::fail('Expected native route conflict.');
        } catch (\RuntimeException $error) {
            self::assertSame('NATIVE_ROUTE_CONFLICT', $error->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $service->allocate('authority', 'not-a-uuid', 'brand', 'root', 'odo', 'request-4');
    }
}

final class FakeIdentityRepository
{
    private array $identity = [];
    private array $history = [];
    private array $idempotency = [];

    public function allocate(array $record, string $key): array
    {
        if (isset($this->idempotency[$key])) return $this->idempotency[$key];
        $record['identity_id'] = 'identity-1';
        $record['revision'] = 1;
        $record['current_path'] = '/' . $record['current_slug'] . '/';
        $this->identity = $record;
        return $this->idempotency[$key] = $record;
    }

    public function change(array $record, string $oldPath, int $expectedRevision, string $key): array
    {
        if (isset($this->idempotency[$key])) return $this->idempotency[$key];
        if ($expectedRevision !== $this->identity['revision']) throw new \RuntimeException('STALE_REVISION');
        $record['revision'] = $expectedRevision + 1;
        $record['current_path'] = '/' . $record['current_slug'] . '/';
        $this->identity = $record;
        $this->history[] = $oldPath;
        return $this->idempotency[$key] = $record;
    }

    public function findCurrentById(string $identityId): ?array { return $this->identity === [] ? null : $this->identity; }

    public function current(): array { return $this->identity; }
    public function historicPaths(): array { return $this->history; }
}
