<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\PublicIdentity\{HistoricPublicRoute, PublicIdentity, PublicIdentityMutationResult};
use NHK\Core\Infrastructure\Http\PublicEntityRoutes;
use PHPUnit\Framework\TestCase;

final class PublicEntityHistoricRouteShapeTest extends TestCase
{
    /** @dataProvider routeShapes */
    public function test_historic_result_uses_persisted_current_path_for_every_route_shape(string $type, string $oldPath, string $currentPath): void
    {
        $identity = new PublicIdentity('identity-' . $type, 'authority', 'owner-' . $type, $type, 'current', 'scope', 'public-route-v1', 2, null, null, $currentPath);
        $history = new HistoricPublicRoute($identity->identityId, $type, 'scope', $oldPath, 'old', 2);
        self::assertSame($currentPath, PublicEntityRoutes::historicCanonicalPath(PublicIdentityMutationResult::accepted($identity, $history)));
    }

    public static function routeShapes(): array
    {
        return [
            ['brand', '/old-brand/', '/brand-current/'],
            ['model', '/old-brand/old-model/', '/brand-current/model-current/'],
            ['variant', '/old-brand/old-model/old-variant/', '/brand-current/model-current/variant-current/'],
            ['movement', '/bo-may/old/', '/bo-may/current/'],
            ['music', '/ban-nhac/old/', '/ban-nhac/current/'],
            ['component', '/linh-kien/old/', '/linh-kien/current/'],
            ['classification', '/phan-loai/old/', '/phan-loai/current/'],
            ['specimen', '/hien-vat/old/', '/hien-vat/current/'],
            ['product', '/san-pham/old/', '/san-pham/current/'],
        ];
    }
}
