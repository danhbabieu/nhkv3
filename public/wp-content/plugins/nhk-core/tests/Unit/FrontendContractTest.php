<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FrontendContractTest extends TestCase
{
    public function test_homepage_uses_query_service_and_semantic_modules_are_not_fixture_lists(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $frontPage = (string) file_get_contents($theme . '/front-page.php');
        $query = (string) file_get_contents($theme . '/inc/class-nhk-home-page-query.php');
        self::assertStringContainsString('NHK_V3_Home_Page_Query', $frontPage);
        self::assertStringNotContainsString('new WP_Query', $frontPage);
        self::assertStringContainsString('nhk_v3_home_semantic_modules', $query);
    }

    public function test_theme_seo_contract_covers_editorial_entity_media_and_video_surfaces(): void
    {
        $functions = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/functions.php');
        foreach (['pre_get_document_title', 'wp_get_canonical_url', 'og:title', 'BreadcrumbList', 'VideoObject', 'nhk_core_media_context', 'nhk_core_video_context'] as $contract) {
            self::assertStringContainsString($contract, $functions);
        }
        self::assertStringContainsString('PublicMediaAssetRoutes', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php'));
        self::assertStringContainsString("'mode' => 'archive', 'type' => \$type", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicEntityRoutes.php'));
        self::assertStringContainsString('nhk_v3_entity_label', $functions);
        $readApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/ReadApi.php');
        self::assertStringContainsString('!$media->active', $readApi);
        self::assertStringContainsString('!$video->active', $readApi);
    }

    public function test_search_template_uses_unified_search_query_boundary(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $index = (string) file_get_contents($theme . '/index.php');
        $query = (string) file_get_contents($theme . '/inc/class-nhk-search-page-query.php');
        self::assertStringContainsString('NHK_V3_Search_Page_Query', $index);
        self::assertStringNotContainsString('new WP_Query', $index);
        self::assertStringContainsString('nhk_v3_search_semantic_results', $query);
        self::assertStringContainsString("home_url('/knowledge/claim/'", $index);
    }

    public function test_post_template_uses_graph_related_query_boundary(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        self::assertStringContainsString("apply_filters('nhk_v3_post_related_content'", (string) file_get_contents($theme . '/single.php'));
        self::assertStringContainsString('function (array $value, int $postId)', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php'));
        self::assertStringContainsString('forPost', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Entity/RelatedContentQuery.php'));
    }

    public function test_v2_archive_aliases_resolve_to_canonical_entity_types(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicEntityRoutes.php');
        self::assertStringContainsString("'thuong-hieu' => 'brand'", $routes);
        self::assertStringContainsString("'hien-vat' => 'specimen'", $routes);
        self::assertStringContainsString("'am-nhac' => 'music'", $routes);
        self::assertStringContainsString('nhk_entity_alias', $routes);
    }
}
