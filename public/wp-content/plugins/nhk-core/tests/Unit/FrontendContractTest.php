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
        self::assertStringContainsString('<br> <em>mang một câu chuyện.</em>', $frontPage);
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

    public function test_theme_accessibility_contract_has_skip_link_keyboard_menu_and_main_targets(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $header = (string) file_get_contents($theme . '/header.php');
        $style = (string) file_get_contents($theme . '/style.css');
        $functions = (string) file_get_contents($theme . '/functions.php');

        self::assertStringContainsString('class="skip-link"', $header);
        self::assertStringContainsString('href="#main-content"', $header);
        self::assertStringContainsString('aria-controls="primary-navigation"', $header);
        self::assertStringContainsString('aria-expanded="false"', $header);
        self::assertStringContainsString('id="primary-navigation"', $header);
        self::assertStringContainsString('.nav-toggle:focus-visible', $style);
        self::assertStringContainsString('display:block!important', $style);
        self::assertStringContainsString('.skip-link:focus', $style);
        self::assertStringContainsString('.entity-card-key', $style);
        self::assertStringContainsString('overflow-wrap:anywhere', $style);
        self::assertStringContainsString("get_theme_file_uri('navigation.js')", $functions);

        foreach (['front-page.php', 'index.php', 'single.php', 'entity.php', 'knowledge.php', 'media.php', 'video.php', 'comparison.php', '404.php'] as $template) {
            self::assertStringContainsString('id="main-content"', (string) file_get_contents($theme . '/' . $template), $template . ' must expose the skip-link target');
        }
    }

    public function test_theme_seo_contract_declares_archive_index_policy(): void
    {
        $functions = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/functions.php');
        self::assertStringContainsString('function nhk_v3_robots(array $robots): array', $functions);
        self::assertStringContainsString("'nhk_entity_page', 'nhk_media_page', 'nhk_video_page', 'nhk_knowledge_page'", $functions);
        self::assertStringContainsString('$robots[\'noindex\'] = true', $functions);
        self::assertStringContainsString('$robots[\'index\'] = true', $functions);
        self::assertStringContainsString("add_filter('wp_robots', 'nhk_v3_robots', 20)", $functions);
        self::assertStringContainsString('nhk_v3_allow_semantic_search_pages', $functions);
        self::assertStringContainsString("add_filter('pre_handle_404', 'nhk_v3_allow_semantic_search_pages', 10, 2)", $functions);
        self::assertStringContainsString('if (is_front_page() || is_home() || is_search()) $canonical = home_url(\'/\');', $functions);
    }

    public function test_admin_contract_associates_labels_with_operational_controls(): void
    {
        $admin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Admin/AdminPage.php');
        foreach (['aria-labelledby="nhk-entity-lookup-heading"', 'for="nhk-entity-type"', 'for="nhk-entity-key"', 'for="nhk-proposal-id"', 'aria-describedby="nhk-proposal-composer-help"', 'id="nhk-source-key"', 'id="nhk-target-key"', 'for="nhk-semantic-id"', 'id="nhk-graph-endpoint-key"', 'id="nhk-video-url"', 'value="video"', 'value="knowledge"', 'value="source"', 'value="evidence"', 'aria-live="polite"'] as $contract) {
            self::assertStringContainsString($contract, $admin);
        }
        self::assertStringContainsString("echo '<option value=\"video\">video</option>';", $admin);
        self::assertStringContainsString("echo '<option value=\"knowledge\">knowledge</option>';", $admin);
        self::assertStringContainsString("echo '<option value=\"source\">source</option>';", $admin);
        self::assertStringContainsString("echo '<option value=\"evidence\">evidence</option>';", $admin);
        self::assertStringContainsString('payload.operation==="ingest"&&payload.entity_type==="video"', $admin);
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
        $searchApi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/SearchApi.php');
        self::assertStringContainsString('$entity->active()', $searchApi);
        self::assertStringContainsString("'semantic_totals' => \$semanticTotals", $searchApi);
    }

    public function test_semantic_archives_render_pagination_from_query_totals(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        foreach (['media.php', 'video.php', 'knowledge.php'] as $template) {
            $contents = (string) file_get_contents($theme . '/' . $template);
            self::assertStringContainsString('entity-pagination', $contents, $template . ' must render archive pagination');
            self::assertStringContainsString('$pages', $contents, $template . ' must derive page count');
        }
    }

    public function test_pagination_marks_the_current_page_for_assistive_technology(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        foreach (['index.php', 'entity.php', 'media.php', 'video.php', 'knowledge.php'] as $template) {
            self::assertStringContainsString("aria-current=\"page\"", (string) file_get_contents($theme . '/' . $template), $template . ' must expose the current page');
        }
    }

    public function test_pagination_wraps_without_horizontal_overflow(): void
    {
        $style = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/entity.css');
        self::assertStringContainsString('.entity-pagination{', $style);
        self::assertStringContainsString('flex-wrap:wrap', $style);
        self::assertStringContainsString('max-width:100%', $style);
    }

    public function test_public_templates_do_not_expose_internal_domain_terms(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        foreach (['front-page.php', 'comparison.php', 'knowledge.php', 'media.php', 'video.php', 'entity.php', 'single.php', 'index.php'] as $template) {
            $contents = (string) file_get_contents($theme . '/' . $template);
            foreach (['Authority reference', 'Knowledge claim', 'Canonical ID', 'entity Video', 'Semantic search', 'Hồ sơ canonical', 'External references', 'media canonical', 'Kho claim canonical', 'atomic claim', 'Revision', 'dữ liệu canonical', 'Trang semantic', 'external reference'] as $internalTerm) {
                self::assertStringNotContainsString($internalTerm, $contents, $template . ' exposes internal term: ' . $internalTerm);
            }
        }
    }

    public function test_public_entity_payload_values_are_reader_facing(): void
    {
        $functions = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/functions.php');
        self::assertStringContainsString('function nhk_v3_public_value', $functions);
        self::assertStringContainsString("'canonical' => 'hồ sơ'", $functions);
        self::assertStringContainsString("'stable key' => 'mã ổn định'", $functions);
        self::assertStringContainsString('nhk_v3_public_value($value)', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/entity.php'));
        self::assertStringContainsString('nhk_v3_public_label((string) $key)', (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/entity.php'));
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

    public function test_v2_search_alias_preserves_query_and_uses_native_wordpress_search(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicEditorialRoutes.php');
        self::assertStringContainsString('legacySearchRedirect', $routes);
        self::assertStringContainsString("\$_GET['q']", $routes);
        self::assertStringContainsString("add_query_arg('s', \$term, home_url('/'))", $routes);
        $routeSmoke = (string) file_get_contents(dirname(__DIR__, 6) . '/tools/frontend-route-smoke.php');
        self::assertStringContainsString("'/tim-kiem/?q=odo' => 301", $routeSmoke);
        self::assertStringContainsString("'/tim-kiem/?q=odo' => '/?s=odo'", $routeSmoke);
        self::assertStringContainsString("'brand-alias', 'model-alias'", $routeSmoke);
        self::assertStringContainsString('explode(\'|\', substr($argument, strlen($prefix)), 2)', $routeSmoke);
        self::assertStringContainsString('optionalRedirects[$route] = $target', $routeSmoke);
    }

    public function test_comparison_surface_uses_entity_query_and_has_a_real_discovery_route(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicComparisonRoutes.php');
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/comparison.php');
        $home = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/front-page.php');
        self::assertStringContainsString('PublicComparisonRoutes', $plugin);
        self::assertStringContainsString("comparison/?$", $routes);
        self::assertStringContainsString('ComparisonPageQuery', $plugin);
        self::assertStringContainsString('name="a"', $template);
        self::assertStringContainsString("home_url('/comparison/')", $home);
        self::assertStringContainsString("'/comparison/' => 200", (string) file_get_contents(dirname(__DIR__, 6) . '/tools/frontend-route-smoke.php'));
        $routeSmoke = (string) file_get_contents(dirname(__DIR__, 6) . '/tools/frontend-route-smoke.php');
        foreach (["'/tri-thuc/page/2/' => 200", "'/goc-chia-se/page/2/' => 200", "'/media/page/2/' => 200", "'/video/page/2/' => 200", "'/knowledge/page/2/' => 200", "'/wp-sitemap.xml' => 200", "'/feed/' => 200"] as $route) {
            self::assertStringContainsString($route, $routeSmoke, 'semantic page-two route must be in smoke coverage');
        }
        self::assertStringContainsString("'/wp-sitemap.xml' => '<sitemapindex'", $routeSmoke);
        self::assertStringContainsString("'/feed/' => '<rss'", $routeSmoke);
        $functions = (string) file_get_contents(dirname(__DIR__, 4) . '/themes/nhk-v3/functions.php');
        self::assertStringContainsString('nhk_core_comparison_context', $functions);
        self::assertStringContainsString('So sánh hồ sơ — Đồng Hồ Nhà Kho', $functions);
    }
}
