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

    public function test_historic_route_contract_resolves_exact_route_scope_and_path_without_wordpress_types(): void
    {
        $resolve = new \ReflectionMethod(HistoricPublicRouteResolver::class, 'resolveExact');

        self::assertSame(['routeType', 'collisionScope', 'path'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $resolve->getParameters()));
        self::assertSame(PublicIdentityMutationResult::class, $resolve->getReturnType()->getName());
        self::assertTrue((new \ReflectionClass(HistoricPublicRouteResolver::class))->isInterface());
    }

    public function test_contract_result_codes_cover_storage_and_ambiguous_history_failures(): void
    {
        self::assertSame('UNAVAILABLE_STORAGE', PublicIdentityMutationResult::UNAVAILABLE_STORAGE);
        self::assertSame('AMBIGUOUS_HISTORY', PublicIdentityMutationResult::AMBIGUOUS_HISTORY);
        self::assertSame('UNKNOWN_ROUTE', PublicIdentityMutationResult::UNKNOWN_ROUTE);
        self::assertSame('CONFLICT', PublicIdentityMutationResult::CONFLICT);
    }
}
