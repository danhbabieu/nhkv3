<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TriThucArchiveRouteTest extends TestCase
{
    public function test_tri_thuc_archive_query_targets_the_canonical_published_category_newest_first(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $template = (string) file_get_contents($theme . '/tri-thuc.php');
        self::assertStringContainsString("'category__in' => [4]", $template);
        self::assertStringContainsString("'post_type' => 'post'", $template);
        self::assertStringContainsString("'post_status' => 'publish'", $template);
        self::assertStringContainsString("'orderby' => 'date'", $template);
        self::assertStringContainsString("'order' => 'DESC'", $template);
        self::assertStringContainsString("'paged' => \$archivePage", $template);
        self::assertStringNotContainsString("category_name=tri-thuc'", $template);
    }

    public function test_tri_thuc_presentation_path_preserves_pagination_without_changing_article_urls(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicEditorialRoutes.php');
        self::assertStringContainsString("'/page/([1-9][0-9]*)/?$'", $routes);
        self::assertStringContainsString('nhk_editorial_route=', $routes);
        self::assertStringContainsString("'tri-thuc'", $routes);
        self::assertStringContainsString('paged=$matches[1]', $routes);
        self::assertStringContainsString("home_url('/tri-thuc/' .", $routes);
    }

    public function test_canonical_category_archive_redirect_is_one_hop_to_the_presentation_route(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicEditorialRoutes.php');
        self::assertStringContainsString('template_redirect', $routes);
        self::assertStringContainsString('tri-thuc-dong-ho', $routes);
        self::assertStringContainsString('wp_safe_redirect(self::triThucPresentationPath($page)', $routes);
        self::assertStringContainsString('redirect_canonical', $routes);
    }

    public function test_tri_thuc_archive_does_not_create_a_duplicate_category_or_use_search_fallback(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicEditorialRoutes.php');
        $archiveBoundary = substr($routes, strpos($routes, 'public function rewrite'), strpos($routes, 'public function template') - strpos($routes, 'public function rewrite'));
        self::assertIsString($archiveBoundary);
        self::assertStringNotContainsString('wp_insert_term', $routes);
        self::assertStringNotContainsString('wp_create_category', $routes);
        self::assertStringNotContainsString("'s'", $archiveBoundary);
        self::assertStringNotContainsString('pre_get_posts', $routes);
    }

    public function test_goc_chia_se_keeps_its_existing_empty_archive_query(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicEditorialRoutes.php');
        self::assertStringContainsString("category_name=' . \$slug", $routes);
    }

    public function test_tri_thuc_archive_renders_native_post_cards_with_fallback_and_pagination(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $template = (string) file_get_contents($theme . '/tri-thuc.php');
        $card = (string) file_get_contents($theme . '/template-parts/article-card.php');
        self::assertStringContainsString('<div class="post-grid">', $template);
        self::assertStringContainsString('$archiveQuery = new WP_Query([', $template);
        self::assertStringContainsString("'category__in' => [4]", $template);
        self::assertStringContainsString("'post_type' => 'post'", $template);
        self::assertStringContainsString("'post_status' => 'publish'", $template);
        self::assertStringContainsString("'orderby' => 'date'", $template);
        self::assertStringContainsString("'order' => 'DESC'", $template);
        self::assertStringContainsString("'paged' => \$archivePage", $template);
        self::assertStringContainsString("'posts_per_page' => get_option('posts_per_page')", $template);
        self::assertStringContainsString('while ($archiveQuery->have_posts())', $template);
        self::assertStringContainsString('$archiveQuery->the_post()', $template);
        self::assertStringContainsString('wp_reset_postdata()', $template);
        self::assertStringContainsString("'total' => (int) \$archiveQuery->max_num_pages", $template);
        self::assertStringContainsString("get_template_part('template-parts/article-card')", $template);
        self::assertStringContainsString('has_post_thumbnail()', $card);
        self::assertStringContainsString('default-archive.svg', $card);
        self::assertStringContainsString('the_permalink()', $card);
        self::assertStringContainsString('nhk_v3_excerpt()', $card);
        self::assertStringContainsString('nhk_v3_public_date()', $card);
    }

    public function test_tri_thuc_archive_exposes_the_canonical_heading_and_url(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $functions = (string) file_get_contents($theme . '/functions.php');
        $template = (string) file_get_contents($theme . '/tri-thuc.php');
        self::assertStringContainsString("'tri-thuc' => 'Tri thức đồng hồ'", $functions);
        self::assertStringContainsString('Tri thức đồng hồ', $template);
        self::assertStringContainsString("\$canonical = home_url('/' . \$editorialRoute . '/')", $functions);
    }

    public function test_tri_thuc_route_selects_the_dedicated_template(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicEditorialRoutes.php');
        self::assertStringContainsString("if (\$route === self::TRI_THUC_ROUTE)", $routes);
        self::assertStringContainsString("locate_template('tri-thuc.php')", $routes);
    }

    public function test_route_smoke_covers_category_redirect_and_tri_thuc_canonical_metadata(): void
    {
        $smoke = (string) file_get_contents(dirname(__DIR__, 6) . '/tools/frontend-route-smoke.php');
        self::assertStringContainsString("'/category/tri-thuc-dong-ho/' => 301", $smoke);
        self::assertStringContainsString("'/category/tri-thuc-dong-ho/page/2/' => 301", $smoke);
        self::assertStringContainsString("'/category/tri-thuc-dong-ho/' => '/tri-thuc/'", $smoke);
        self::assertStringContainsString("'/category/tri-thuc-dong-ho/page/2/' => '/tri-thuc/page/2/'", $smoke);
        self::assertStringContainsString('<title>Tri thức đồng hồ — Đồng Hồ Nhà Kho</title>', $smoke);
        self::assertStringContainsString("'<link rel=\"canonical\" href=\"' . \$base . '/tri-thuc/\"'", $smoke);
    }
}
