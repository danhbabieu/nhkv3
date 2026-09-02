<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Entity\ComparisonPageQuery;

final class PublicComparisonRoutes
{
    public function __construct(private ComparisonPageQuery $query) {}

    public function register(): void
    {
        add_filter('query_vars', function (array $vars): array {
            foreach (['nhk_comparison_route', 'nhk_comparison_legacy'] as $name) if (!in_array($name, $vars, true)) $vars[] = $name;
            return $vars;
        });
        add_action('init', [$this, 'rewrite']);
        add_action('template_redirect', [$this, 'legacyRedirect'], 1);
        add_filter('template_include', [$this, 'template']);
    }

    public function rewrite(): void
    {
        add_rewrite_rule('^so-sanh/?$', 'index.php?nhk_comparison_route=1', 'top');
        add_rewrite_rule('^comparison/?$', 'index.php?nhk_comparison_legacy=1', 'top');
    }

    public function legacyRedirect(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli' || (string) get_query_var('nhk_comparison_legacy') === '') return;
        wp_safe_redirect(home_url('/so-sanh/'), 301, 'NHK canonical comparison route');
        exit;
    }

    public function template(string $template): string
    {
        if ((string) get_query_var('nhk_comparison_route') === '') return $template;
        $left = isset($_GET['a']) && is_scalar($_GET['a']) ? sanitize_text_field(wp_unslash((string) $_GET['a'])) : '';
        $right = isset($_GET['b']) && is_scalar($_GET['b']) ? sanitize_text_field(wp_unslash((string) $_GET['b'])) : '';
        $GLOBALS['nhk_core_comparison_context'] = ['mode' => 'compare', 'comparison' => $this->query->read($left, $right)];
        $found = locate_template('comparison.php');
        return $found !== '' ? $found : $template;
    }
}
