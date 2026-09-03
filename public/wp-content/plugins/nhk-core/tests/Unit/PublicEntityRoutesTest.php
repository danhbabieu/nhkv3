<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Http\PublicEntityRoutes;
use PHPUnit\Framework\TestCase;

final class PublicEntityRoutesTest extends TestCase
{
    public function test_historical_o_do_route_redirects_once_to_odo(): void
    {
        self::assertSame('/odo/', PublicEntityRoutes::canonicalRedirectTarget('/o-do/', '/odo/'));
        self::assertSame('/odo/odo-36/', PublicEntityRoutes::canonicalRedirectTarget('/o-do/o-do-36/', '/odo/odo-36/'));
        self::assertNull(PublicEntityRoutes::canonicalRedirectTarget('/odo/', '/odo/'));
    }
}
