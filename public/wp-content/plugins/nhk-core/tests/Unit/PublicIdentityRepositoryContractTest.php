<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Contracts\PublicIdentity\HistoricPublicRouteResolver;
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Domain\PublicIdentity\HistoricPublicRoute;
use NHK\Core\Domain\PublicIdentity\PublicIdentity;
use NHK\Core\Domain\PublicIdentity\PublicIdentityMutationResult;
use PHPUnit\Framework\TestCase;

final class PublicIdentityRepositoryContractTest extends TestCase
{
    public function test_repository_contract_has_owner_and_route_scope_lookups_for_the_two_uniqueness_boundaries(): void
    {
        $ownerLookup = new \ReflectionMethod(PublicIdentityRepository::class, 'findByOwner');
        $routeLookup = new \ReflectionMethod(PublicIdentityRepository::class, 'findByRoute');

        self::assertSame(['ownerKind', 'ownerId'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $ownerLookup->getParameters()));
        self::assertSame(['routeType', 'collisionScope', 'slug'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $routeLookup->getParameters()));
        self::assertSame('?NHK\\Core\\Domain\\PublicIdentity\\PublicIdentity', (string) $ownerLookup->getReturnType());
        self::assertSame('?NHK\\Core\\Domain\\PublicIdentity\\PublicIdentity', (string) $routeLookup->getReturnType());
    }

    public function test_repository_contract_uses_expected_revision_for_a_single_cas_update(): void
    {
        $update = new \ReflectionMethod(PublicIdentityRepository::class, 'update');

        self::assertSame(['identity', 'expectedRevision'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $update->getParameters()));
        self::assertSame(PublicIdentityMutationResult::class, $update->getReturnType()->getName());
    }

    public function test_repository_contract_reference_rejects_a_second_current_identity_for_the_same_owner(): void
    {
        $repository = new InMemoryPublicIdentityRepositoryContractReference();

        self::assertTrue($repository->create($this->identity('identity-001', 'owner-001', 'odo'))->accepted);
        $duplicateOwner = $repository->create($this->identity('identity-002', 'owner-001', 'o-do'));

        self::assertFalse($duplicateOwner->accepted);
        self::assertSame(PublicIdentityMutationResult::CONFLICT, $duplicateOwner->code);
    }

    public function test_repository_contract_reference_rejects_a_duplicate_route_scope_and_slug(): void
    {
        $repository = new InMemoryPublicIdentityRepositoryContractReference();

        self::assertTrue($repository->create($this->identity('identity-001', 'owner-001', 'odo'))->accepted);
        $duplicateRoute = $repository->create($this->identity('identity-002', 'owner-002', 'odo'));

        self::assertFalse($duplicateRoute->accepted);
        self::assertSame(PublicIdentityMutationResult::CONFLICT, $duplicateRoute->code);
    }

    public function test_repository_contract_reference_rejects_a_current_route_that_collides_with_history(): void
    {
        $repository = new InMemoryPublicIdentityRepositoryContractReference();

        self::assertTrue($repository->appendHistoricRoute(new HistoricPublicRoute('identity-001', 'brand', 'root', '/odo/', 'odo', 2))->accepted);
        $currentCollision = $repository->create($this->identity('identity-002', 'owner-002', 'odo'));

        self::assertFalse($currentCollision->accepted);
        self::assertSame(PublicIdentityMutationResult::CONFLICT, $currentCollision->code);
    }

    public function test_historic_route_contract_resolves_exact_route_scope_and_path_without_wordpress_types(): void
    {
        $resolve = new \ReflectionMethod(HistoricPublicRouteResolver::class, 'resolveExact');

        self::assertSame(['routeType', 'collisionScope', 'path'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $resolve->getParameters()));
        self::assertSame(PublicIdentityMutationResult::class, $resolve->getReturnType()->getName());
        self::assertTrue((new \ReflectionClass(HistoricPublicRouteResolver::class))->isInterface());
    }

    public function test_repository_contract_accepts_append_only_historic_route_records(): void
    {
        $append = new \ReflectionMethod(PublicIdentityRepository::class, 'appendHistoricRoute');

        self::assertSame(['historicRoute'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $append->getParameters()));
        self::assertSame(PublicIdentityMutationResult::class, $append->getReturnType()->getName());
    }

    public function test_contract_result_codes_cover_storage_and_ambiguous_history_failures(): void
    {
        self::assertSame('UNAVAILABLE_STORAGE', PublicIdentityMutationResult::UNAVAILABLE_STORAGE);
        self::assertSame('AMBIGUOUS_HISTORY', PublicIdentityMutationResult::AMBIGUOUS_HISTORY);
        self::assertSame('UNKNOWN_ROUTE', PublicIdentityMutationResult::UNKNOWN_ROUTE);
        self::assertSame('CONFLICT', PublicIdentityMutationResult::CONFLICT);
    }

    private function identity(string $identityId, string $ownerId, string $slug): PublicIdentity
    {
        return new PublicIdentity($identityId, 'authority', $ownerId, 'brand', $slug, 'root', 'public-route-v1', 1);
    }
}

final class InMemoryPublicIdentityRepositoryContractReference implements PublicIdentityRepository
{
    /** @var list<PublicIdentity> */
    private array $identities = [];
    /** @var list<HistoricPublicRoute> */
    private array $historicRoutes = [];

    public function findByOwner(string $ownerKind, string $ownerId): ?PublicIdentity
    {
        foreach ($this->identities as $identity) {
            if ($identity->ownerKind === $ownerKind && $identity->ownerId === $ownerId) return $identity;
        }
        return null;
    }

    public function findByRoute(string $routeType, string $collisionScope, string $slug): ?PublicIdentity
    {
        foreach ($this->identities as $identity) {
            if ($identity->routeType === $routeType && $identity->collisionScope === $collisionScope && $identity->currentSlug === $slug) return $identity;
        }
        return null;
    }

    public function create(PublicIdentity $identity): PublicIdentityMutationResult
    {
        if ($this->findByOwner($identity->ownerKind, $identity->ownerId) !== null || $this->findByRoute($identity->routeType, $identity->collisionScope, $identity->currentSlug) !== null || $this->hasHistoricCollision($identity)) {
            return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        }
        $this->identities[] = $identity;
        return PublicIdentityMutationResult::accepted($identity);
    }

    public function update(PublicIdentity $identity, int $expectedRevision): PublicIdentityMutationResult
    {
        return $identity->revision === $expectedRevision ? PublicIdentityMutationResult::accepted($identity) : PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::STALE_REVISION);
    }

    public function appendHistoricRoute(HistoricPublicRoute $historicRoute): PublicIdentityMutationResult
    {
        foreach ($this->historicRoutes as $existing) {
            if ($existing->routeType === $historicRoute->routeType && $existing->collisionScope === $historicRoute->collisionScope && $existing->oldSlug === $historicRoute->oldSlug) return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        }
        if ($this->findByRoute($historicRoute->routeType, $historicRoute->collisionScope, $historicRoute->oldSlug) !== null) return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        $this->historicRoutes[] = $historicRoute;
        return PublicIdentityMutationResult::acceptedHistoricRoute($historicRoute);
    }

    private function hasHistoricCollision(PublicIdentity $identity): bool
    {
        foreach ($this->historicRoutes as $historicRoute) {
            if ($historicRoute->routeType === $identity->routeType && $historicRoute->collisionScope === $identity->collisionScope && $historicRoute->oldSlug === $identity->currentSlug) return true;
        }
        return false;
    }
}
