<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DictionarySeoSurfaceParityTest extends TestCase
{
    public function test_dictionary_route_has_one_shared_seo_context_and_no_private_head_emitter(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/src/Infrastructure/Http/PublicDictionaryRoutes.php');
        $theme = (string) file_get_contents($root . '/../../themes/nhk-v3/functions.php');

        self::assertStringNotContainsString("add_action('wp_head', [\$this, 'head']", $routes);
        self::assertStringContainsString("'nhk_core_dictionary_context'", $theme);
    }

    public function test_theme_serializer_prefers_normalized_absolute_urls_and_keeps_wp_robots_as_owner(): void
    {
        $root = dirname(__DIR__, 2);
        $theme = (string) file_get_contents($root . '/../../themes/nhk-v3/functions.php');

        self::assertStringContainsString("['canonical_url']", $theme);
        self::assertStringContainsString("['canonical_path']", $theme);
        self::assertStringContainsString("add_filter('wp_robots', 'nhk_v3_robots', 20)", $theme);
        self::assertStringNotContainsString("<meta name=\"robots\"", $theme);
    }
}
