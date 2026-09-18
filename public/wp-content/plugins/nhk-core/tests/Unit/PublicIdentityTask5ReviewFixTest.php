<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\PublicIdentity\HistoricPublicRouteService;
use NHK\Core\Domain\PublicIdentity\{HistoricPublicRoute, PublicIdentity, PublicIdentityMutationResult};
use NHK\Core\Infrastructure\Http\PublicMediaVideoRoutes;
use NHK\Core\Infrastructure\PublicIdentity\WpdbHistoricPublicRouteResolver;
use PHPUnit\Framework\TestCase;

final class PublicIdentityTask5ReviewFixTest extends TestCase
{
    public function test_exact_resolution_preserves_stored_identity_scope_and_old_slug(): void
    {
        $service = new HistoricPublicRouteService(new ReviewExactResolver(), static fn (): bool => true);
        $result = $service->resolveExact('video', 'youtube', '/video/odo-36-10-gai-carillon-P4KaHX3LBOw/');
        self::assertTrue($result->accepted);
        self::assertSame('01a06815-1e51-7964-b004-1ba79e488ad1', $result->historicRoute?->identityId);
        self::assertSame('youtube', $result->historicRoute?->collisionScope);
        self::assertSame('odo-36-10-gai-carillon-P4KaHX3LBOw', $result->historicRoute?->oldSlug);
    }

    public function test_ineligible_owner_is_not_redirected(): void
    {
        $routes = new PublicMediaVideoRoutes(null, new HistoricPublicRouteService(new ReviewExactResolver(false)));
        self::assertSame(['status' => 404], $routes->historicRedirect('/video/old/'));
    }

    public function test_missing_owner_eligibility_callback_fails_closed(): void
    {
        $result = (new HistoricPublicRouteService(new ReviewExactResolver()))->resolveExact('video', 'youtube', '/video/old/');
        self::assertFalse($result->accepted);
        self::assertSame(PublicIdentityMutationResult::CONFLICT, $result->code);
    }

    public function test_wpdb_change_and_historic_lookup_are_scoped(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Infrastructure/PublicIdentity/WpdbPublicIdentityRepository.php');
        self::assertIsString($source);
        self::assertStringContainsString('(identity_uuid,route_type,collision_scope,route_path', $source);
        self::assertStringContainsString('WHERE h.route_type=%s AND h.collision_scope=%s AND h.route_path=%s', $source);
        self::assertStringNotContainsString("public function resolveHistoric(string \$path): array { return ['status'=>'NOT_FOUND']; }", $source);
    }


    public function test_migration_history_scope_is_part_of_exact_storage_boundary(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../src/Infrastructure/Migration/PublicIdentityMigration014.php');
        self::assertIsString($sql);
        self::assertStringContainsString('collision_scope VARCHAR(191) NOT NULL', $sql);
        self::assertStringContainsString('route_type,collision_scope,route_path', $sql);
    }

    public function test_path_resolution_uses_the_stored_route_context_instead_of_guessing_type_or_scope(): void
    {
        $result = (new HistoricPublicRouteService(new ReviewPathResolver(), static fn (): bool => true))->resolvePath('/old-brand/old-model/');
        self::assertTrue($result->accepted);
        self::assertSame('model', $result->historicRoute?->routeType);
        self::assertSame('brand:stored-parent', $result->historicRoute?->collisionScope);
    }

    public function test_legacy_resolve_hydrates_the_exact_resolver_without_repository_arity_mismatch(): void
    {
        $result = (new HistoricPublicRouteService(new ReviewPathResolver(), static fn (): bool => true))->resolveHistoric('/old-brand/old-model/');
        self::assertSame('FOUND', $result['status']);
        self::assertSame('/current-brand/current-model/', $result['target']);
    }

    public function test_wpdb_historic_resolver_uses_compatible_path_lookup_without_one_argument_arity_mismatch(): void
    {
        $repository = new ReviewWpdbRouteRepository();
        $result = (new WpdbHistoricPublicRouteResolver($repository))->resolveHistoric('/old-brand/old-model/');

        self::assertSame(['/old-brand/old-model/'], $repository->paths);
        self::assertSame('FOUND', $result['status']);
        self::assertSame('/current-brand/current-model/', $result['target']);
        self::assertSame(1, $result['hops']);
    }

    public function test_hydration_preserves_a_persisted_hierarchical_current_path(): void
    {
        $reflection = new \ReflectionMethod(\NHK\Core\Infrastructure\PublicIdentity\WpdbPublicIdentityRepository::class, 'hydrate');
        $reflection->setAccessible(true);
        $repository = new \NHK\Core\Infrastructure\PublicIdentity\WpdbPublicIdentityRepository(new \stdClass());
        $row = ['identity_uuid' => \NHK\Core\Shared\Uuid\UuidCodec::toBinary('01a06815-1e51-7964-b004-1ba79e488ad1'), 'owner_kind' => 'authority', 'owner_uuid' => \NHK\Core\Shared\Uuid\UuidCodec::toBinary('01a06815-1e51-7964-b004-1ba79e488ad1'), 'route_type' => 'model', 'current_slug' => 'model-current', 'current_path' => '/brand-current/model-current/', 'collision_scope' => 'brand:stored-parent', 'route_policy_version' => 'public-route-v1', 'revision' => 4];
        self::assertSame('/brand-current/model-current/', $reflection->invoke($repository, $row)['current_path']);
    }

    public function test_exact_query_uses_aliased_historic_and_current_columns(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Infrastructure/PublicIdentity/WpdbPublicIdentityRepository.php');
        self::assertIsString($source);
        self::assertStringContainsString('h.route_path AS historic_path', $source);
        self::assertStringContainsString('i.current_path AS current_path', $source);
        self::assertStringNotContainsString('SELECT h.*,i.*', $source);
    }
}

final class ReviewPathResolver
{
    public function resolvePath(string $path): PublicIdentityMutationResult
    {
        return PublicIdentityMutationResult::accepted(new PublicIdentity(
            '01a06815-1e51-7964-b004-1ba79e488ad1', 'authority',
            '01a06815-1e51-7964-b004-1ba79e488ad1', 'model', 'current-model',
            'brand:stored-parent', 'public-route-v1', 4, null, null,
            '/current-brand/current-model/',
        ), new HistoricPublicRoute('01a06815-1e51-7964-b004-1ba79e488ad1', 'model', 'brand:stored-parent', $path, 'old-model', 4));
    }
}

final class ReviewWpdbRouteRepository
{
    /** @var list<string> */
    public array $paths = [];

    public function resolvePath(string $path): PublicIdentityMutationResult
    {
        $this->paths[] = $path;
        return (new ReviewPathResolver())->resolvePath($path);
    }
}

final class ReviewExactResolver
{
    public function __construct(private bool $eligible = true) {}
    public function resolveExact(string $routeType, string $scope, string $path): PublicIdentityMutationResult
    {
        if (!$this->eligible) return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        $history = new HistoricPublicRoute(
            '01a06815-1e51-7964-b004-1ba79e488ad1', $routeType, $scope,
            $path, 'odo-36-10-gai-carillon-P4KaHX3LBOw', 3,
        );
        if (!$this->eligible) return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        return PublicIdentityMutationResult::accepted(new PublicIdentity(
            '01a06815-1e51-7964-b004-1ba79e488ad1', 'video',
            '01a06815-1e51-7964-b004-1ba79e488ad1', 'video',
            'odo-36-10-gai-carillon-p4kahx3lbow', $scope, 'public-route-v1', 4,
            null, null, '/video/odo-36-10-gai-carillon-p4kahx3lbow/',
        ), $history);
    }
}
