<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\PublicIdentity\HistoricPublicRouteService;
use PHPUnit\Framework\TestCase;
use NHK\Tests\Support\FakeHistoricResolverRepository;

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
