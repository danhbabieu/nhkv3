<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

final class PublicEditorialRoutes
{
    public function register(): void
    {
        add_filter('query_vars', function (array $vars): array { if (!in_array('nhk_editorial_route', $vars, true)) $vars[] = 'nhk_editorial_route'; return $vars; });
        add_action('init', [$this, 'rewrite']);
        add_filter('template_include', [$this, 'template']);
    }

    public function rewrite(): void
    {
        foreach (['tri-thuc', 'goc-chia-se'] as $slug) {
            add_rewrite_rule('^' . $slug . '/page/([1-9][0-9]*)/?$', 'index.php?nhk_editorial_route=' . $slug . '&category_name=' . $slug . '&paged=$matches[1]', 'top');
            add_rewrite_rule('^' . $slug . '/?$', 'index.php?nhk_editorial_route=' . $slug . '&category_name=' . $slug, 'top');
        }
    }

    public function template(string $template): string
    {
        $route = (string) get_query_var('nhk_editorial_route');
        if ($route === '' || term_exists($route, 'category')) return $template;
        global $wp_query;
        if (isset($wp_query) && is_object($wp_query)) { $wp_query->is_404 = false; $wp_query->is_archive = true; }
        status_header(200);
        $found = locate_template('index.php');
        return $found !== '' ? $found : $template;
    }
}
