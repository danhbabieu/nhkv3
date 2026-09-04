<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\PublicIdentity\HistoricPublicRouteService;
use NHK\Core\Domain\PublicIdentity\{HistoricPublicRoute, PublicIdentity, PublicIdentityMutationResult};
use PHPUnit\Framework\TestCase;

final class HistoricPublicRouteResolverTest extends TestCase
{
    public function test_two_sequential_changes_resolve_the_old_path_directly_to_current(): void
    {
        $resolver = new HistoricPublicRouteService(new FakeHistoricResolverRepository());
        $result = $resolver->resolve('/old-video/');

        self::assertSame('FOUND', $result['status']);
        self::assertSame('/video/odo-36-10-gai-carillon-p4kahx3lbow/', $result['target']);
        self::assertSame(1, $result['hops']);
    }

    /** @dataProvider failClosedCases */
    public function test_missing_ambiguous_ineligible_and_loop_history_fail_closed(string $status): void
    {
        $resolver = new HistoricPublicRouteService(new FakeHistoricResolverRepository($status));
        self::assertSame($status, $resolver->resolve('/legacy/')['status']);
    }

    public static function failClosedCases(): array
    {
        return [['NOT_FOUND'], ['AMBIGUOUS'], ['INELIGIBLE'], ['NATIVE_ROUTE_CONFLICT'], ['LOOP']];
    }
}

final class FakeHistoricResolverRepository
{
    public function __construct(private string $status = 'FOUND') {}
    public function resolveHistoric(string $path): array
    {
        if ($this->status !== 'FOUND') return ['status' => $this->status];
        return ['status' => 'FOUND', 'target' => '/video/odo-36-10-gai-carillon-p4kahx3lbow/', 'hops' => 1];
    }
    public function resolveExact(string $routeType, string $scope, string $path): PublicIdentityMutationResult
    {
        if ($this->status !== 'FOUND') return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        return PublicIdentityMutationResult::accepted(new PublicIdentity('01a06815-1e51-7964-b004-1ba79e488ad1', 'video', '01a06815-1e51-7964-b004-1ba79e488ad1', 'video', 'odo-36-10-gai-carillon-p4kahx3lbow', $scope, 'public-route-v1', 4, null, null, '/video/odo-36-10-gai-carillon-p4kahx3lbow/'), new HistoricPublicRoute('01a06815-1e51-7964-b004-1ba79e488ad1', $routeType, $scope, $path, 'odo-36-10-gai-carillon-P4KaHX3LBOw', 3));
    }
}
