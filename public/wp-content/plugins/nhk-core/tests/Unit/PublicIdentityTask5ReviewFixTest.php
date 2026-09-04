<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\PublicIdentity\HistoricPublicRouteService;
use NHK\Core\Domain\PublicIdentity\{HistoricPublicRoute, PublicIdentity, PublicIdentityMutationResult};
use NHK\Core\Infrastructure\Http\PublicMediaVideoRoutes;
use PHPUnit\Framework\TestCase;

final class PublicIdentityTask5ReviewFixTest extends TestCase
{
    public function test_exact_resolution_preserves_stored_identity_scope_and_old_slug(): void
    {
        $service = new HistoricPublicRouteService(new ReviewExactResolver());
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

    public function test_migration_history_scope_is_part_of_exact_storage_boundary(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../src/Infrastructure/Migration/PublicIdentityMigration014.php');
        self::assertIsString($sql);
        self::assertStringContainsString('collision_scope VARCHAR(191) NOT NULL', $sql);
        self::assertStringContainsString('route_type,collision_scope,route_path', $sql);
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
        return PublicIdentityMutationResult::acceptedHistoricRoute($history, new PublicIdentity(
            '01a06815-1e51-7964-b004-1ba79e488ad1', 'video',
            '01a06815-1e51-7964-b004-1ba79e488ad1', 'video',
            'odo-36-10-gai-carillon-p4kahx3lbow', $scope, 'public-route-v1', 4,
        ));
    }
}
