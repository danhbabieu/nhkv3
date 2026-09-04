<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Infrastructure\Http\PublicMediaVideoRoutes;
use PHPUnit\Framework\TestCase;
final class PublicMediaRouteGateTest extends TestCase
{
    public function test_standalone_media_detail_is_constitutionally_blocked(): void { self::assertFalse(PublicMediaVideoRoutes::mediaDetailIsPublic()); self::assertSame('CONSTITUTION_CONFLICT', PublicMediaVideoRoutes::mediaDetailGateReason()); }
    public function test_media_slug_lookup_cannot_create_a_public_detail_page(): void { self::assertNull(PublicMediaVideoRoutes::publicMediaDetail(null, 'any-slug')); }
    public function test_asset_delivery_path_remains_delivery_only(): void { self::assertSame('/media/asset/asset-uuid/', PublicMediaVideoRoutes::assetDeliveryPath('asset-uuid')); self::assertStringNotContainsString('/media/asset/asset-uuid/', '/media/asset-uuid/'); }
}
