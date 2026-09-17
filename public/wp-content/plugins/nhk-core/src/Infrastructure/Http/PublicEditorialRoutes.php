<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

final class PublicEditorialRoutes
{
    private const TRI_THUC_ROUTE = 'tri-thuc';
    private const TRI_THUC_CATEGORY_ID = 4;
    private const TRI_THUC_CATEGORY_SLUG = 'tri-thuc-dong-ho';

    public function register(): void
    {
        add_filter('query_vars', function (array $vars): array { if (!in_array('nhk_editorial_route', $vars, true)) $vars[] = 'nhk_editorial_route'; return $vars; });
        add_action('init', [$this, 'rewrite']);
        add_action('template_redirect', [$this, 'legacySearchRedirect'], 1);
        add_action('template_redirect', [$this, 'canonicalCategoryRedirect'], 2);
        add_filter('redirect_canonical', [$this, 'suppressPresentationRedirect'], 10, 2);
        add_filter('template_include', [$this, 'template']);
    }

    public function legacySearchRedirect(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli') return;
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($path) || rtrim('/' . trim($path, '/'), '/') !== '/tim-kiem') return;
        $term = isset($_GET['q']) && is_scalar($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        wp_safe_redirect(add_query_arg('s', $term, home_url('/')), 301, 'NHK V2 search compatibility');
        exit;
    }

    public function rewrite(): void
    {
        foreach (['tri-thuc', 'goc-chia-se'] as $slug) {
            $query = 'index.php?nhk_editorial_route=' . $slug;
            if ($slug !== self::TRI_THUC_ROUTE) $query .= '&category_name=' . $slug;
            add_rewrite_rule('^' . $slug . '/page/([1-9][0-9]*)/?$', $query . '&paged=$matches[1]', 'top');
            add_rewrite_rule('^' . $slug . '/?$', $query, 'top');
        }
    }

    public function canonicalCategoryRedirect(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli') return;
        if ((string) get_query_var('nhk_editorial_route') === self::TRI_THUC_ROUTE || !is_category()) return;
        $term = get_queried_object();
        if (!is_object($term) || (string) ($term->taxonomy ?? '') !== 'category' || (int) ($term->term_id ?? 0) !== self::TRI_THUC_CATEGORY_ID || (string) ($term->slug ?? '') !== self::TRI_THUC_CATEGORY_SLUG) return;
        $page = max(1, (int) get_query_var('paged', 1));
        wp_safe_redirect(self::triThucPresentationPath($page), 301, 'NHK canonical Tri thức archive');
        exit;
    }

    public function suppressPresentationRedirect(mixed $redirect, string $requested): mixed
    {
        return (string) get_query_var('nhk_editorial_route') === self::TRI_THUC_ROUTE ? false : $redirect;
    }

    public function template(string $template): string
    {
        $route = (string) get_query_var('nhk_editorial_route');

        if ($route === self::TRI_THUC_ROUTE) {
            global $wp_query;
            if (isset($wp_query) && is_object($wp_query)) { $wp_query->is_404 = false; $wp_query->is_archive = true; }
            status_header(200);
            $found = locate_template('tri-thuc.php');
            return $found !== '' ? $found : $template;
        }

        if ($route === '' || term_exists($route, 'category')) return $template;
        global $wp_query;
        if (isset($wp_query) && is_object($wp_query)) { $wp_query->is_404 = false; $wp_query->is_archive = true; }
        status_header(200);
        $found = locate_template('index.php');
        return $found !== '' ? $found : $template;
    }

    private static function triThucPresentationPath(int $page): string
    {
        $page = max(1, $page);
        return home_url('/tri-thuc/' . ($page > 1 ? 'page/' . $page . '/' : ''));
    }
}
