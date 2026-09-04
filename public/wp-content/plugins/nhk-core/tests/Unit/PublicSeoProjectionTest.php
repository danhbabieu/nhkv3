<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Seo\PublicSeoProjection;
use PHPUnit\Framework\TestCase;

final class PublicSeoProjectionTest extends TestCase
{
    public function test_one_eligible_public_url_is_reused_by_every_seo_and_link_surface(): void
    {
        $projection = new PublicSeoProjection();
        $result = $projection->project([
            'path' => '/thuong-hieu/odo/',
            'eligible' => true,
            'blockers' => [],
            'warnings' => [],
            'revision' => 7,
        ], [
            'title' => 'Ô Đô',
            'description' => 'Hồ sơ Ô Đô trong kho NHK.',
            'type' => 'entity',
        ]);

        foreach (['canonical', 'sitemap', 'breadcrumb', 'card', 'search', 'internal_link'] as $surface) {
            self::assertSame('/thuong-hieu/odo/', $result[$surface], $surface);
        }
        self::assertSame('/thuong-hieu/odo/', $result['open_graph']['url']);
        self::assertSame('/thuong-hieu/odo/', $result['json_ld']['url']);
        self::assertSame('/thuong-hieu/odo/', $result['json_ld']['mainEntityOfPage']);
        self::assertTrue($result['indexable']);
    }

    public function test_non_eligible_url_is_not_emitted_and_failure_states_remain_distinct(): void
    {
        $projection = new PublicSeoProjection();
        foreach (['empty', 'unavailable', 'hydration_loss', 'malformed', 'collision', 'infrastructure'] as $state) {
            $result = $projection->project([
                'path' => null,
                'eligible' => false,
                'blockers' => [$state],
                'warnings' => [],
                'state' => $state,
            ], ['title' => 'Không khả dụng', 'description' => '']);

            self::assertFalse($result['indexable'], $state);
            self::assertNull($result['canonical'], $state);
            self::assertSame([$state], $result['blockers'], $state);
            self::assertArrayNotHasKey('url', $result['json_ld'], $state);
        }
    }
}
