<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Domain\PublicIdentity\PublicUrlResult;
use PHPUnit\Framework\TestCase;

final class PublicSeoProjectionTest extends TestCase
{
    public function test_one_public_url_result_is_reused_by_all_seo_and_link_surfaces(): void
    {
        $path = '/thuong-hieu/odo/';
        $result = (new PublicSeoProjection())->project(
            new PublicUrlResult($path, true, identityRevision: 7),
            ['title' => 'Ô Đô', 'description' => 'Hồ sơ Ô Đô trong kho NHK.', 'type' => 'Thing'],
        );

        foreach (['canonical', 'sitemap', 'breadcrumb', 'card', 'search', 'internal_link'] as $surface) {
            self::assertSame($path, $result[$surface], $surface);
        }
        self::assertSame($path, $result['open_graph']['url']);
        self::assertSame($path, $result['json_ld']['url']);
        self::assertSame($path, $result['json_ld']['mainEntityOfPage']);
        self::assertTrue($result['indexable']);
        self::assertSame(7, $result['identity_revision']);
    }

    public function test_ineligible_public_url_result_preserves_blockers_without_emitting_a_route(): void
    {
        $result = (new PublicSeoProjection())->project(
            new PublicUrlResult(null, false, ['HYDRATION_LOSS']),
            ['title' => 'Không khả dụng', 'description' => ''],
        );

        self::assertFalse($result['indexable']);
        self::assertNull($result['canonical']);
        self::assertSame(['HYDRATION_LOSS'], $result['blockers']);
        self::assertSame([], $result['open_graph']);
        self::assertSame([], $result['json_ld']);
    }
}
