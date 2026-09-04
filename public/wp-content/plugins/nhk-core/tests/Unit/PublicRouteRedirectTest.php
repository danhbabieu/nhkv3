<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Http\PublicMediaVideoRoutes;
use NHK\Core\Application\PublicIdentity\HistoricPublicRouteService;
use PHPUnit\Framework\TestCase;

final class PublicRouteRedirectTest extends TestCase
{
    public function test_malformed_canary_path_redirects_once_to_current_video_path(): void
    {
        $routes = new PublicMediaVideoRoutes(null, new HistoricPublicRouteService(new FakeHistoricResolverRepository()));

        self::assertSame([
            'status' => 301,
            'location' => '/video/odo-36-10-gai-carillon-p4kahx3lbow/',
        ], $routes->historicRedirect('/video/odo-36-10-gai-carillon-P4KaHX3LBOw/'));
    }

    public function test_unresolvable_old_path_is_not_a_redirect_or_a_200(): void
    {
        $routes = new PublicMediaVideoRoutes(null, new HistoricPublicRouteService(new FakeHistoricResolverRepository('NOT_FOUND')));

        self::assertSame(['status' => 404], $routes->historicRedirect('/video/unknown/'));
    }
}
