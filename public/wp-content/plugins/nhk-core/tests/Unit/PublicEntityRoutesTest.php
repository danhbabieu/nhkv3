<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Http\PublicEntityRoutes;
use NHK\Core\Infrastructure\Http\LegacyUrlRedirects;
use PHPUnit\Framework\TestCase;

final class PublicEntityRoutesTest extends TestCase
{
    public function test_historical_o_do_route_redirects_once_to_odo(): void
    {
        self::assertSame('/odo/', PublicEntityRoutes::canonicalRedirectTarget('/o-do/', '/odo/'));
        self::assertSame('/odo/odo-36/', PublicEntityRoutes::canonicalRedirectTarget('/o-do/o-do-36/', '/odo/odo-36/'));
        self::assertNull(PublicEntityRoutes::canonicalRedirectTarget('/odo/', '/odo/'));
    }

    public function test_legacy_redirect_defers_to_a_semantic_brand_root_route(): void
    {
        self::assertTrue(LegacyUrlRedirects::shouldDeferForSemanticRoot('/odo/', 'brand'));
        self::assertFalse(LegacyUrlRedirects::shouldDeferForSemanticRoot('/odo/article/', 'brand'));
        self::assertFalse(LegacyUrlRedirects::shouldDeferForSemanticRoot('/odo/', 'model'));
    }

    public function test_wordpress_canonical_redirect_defers_to_a_semantic_brand_root_route(): void
    {
        self::assertNull(LegacyUrlRedirects::filterCanonicalRedirect('https://demo.1945.vn/odo-10-con-10-bua-chuong-kep/', '/odo/', 'brand'));
        self::assertSame('https://demo.1945.vn/other/', LegacyUrlRedirects::filterCanonicalRedirect('https://demo.1945.vn/other/', '/odo/article/', 'brand'));
    }

    public function test_profile_detail_rewrite_preserves_the_registered_route_prefix_for_resolution(): void
    {
        self::assertSame('dong-ho-cong-cong', PublicEntityRoutes::profileDetailRouteKey('clock_type', 'cong-cong'));
        self::assertSame('dong-ho-thap', PublicEntityRoutes::profileDetailRouteKey('clock_type', 'thap'));
        self::assertSame('', PublicEntityRoutes::profileDetailRouteKey('clock_type', ''));
    }
}
