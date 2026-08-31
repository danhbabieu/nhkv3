<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Entity\EntityPageQuery;
use NHK\Core\Domain\Authority\EntityTypeRegistry;

final class PublicEntityRoutes
{
    /** @var array<string,string> */
    private const LEGACY_ARCHIVE_ALIASES = ['thuong-hieu' => 'brand', 'hien-vat' => 'specimen', 'am-nhac' => 'music'];

    public function __construct(private EntityPageQuery $query, private EntityTypeRegistry $types) {}

    public function register(): void
    {
        add_filter('query_vars', function (array $vars): array { foreach (['nhk_entity_type', 'nhk_entity_key', 'nhk_entity_page', 'nhk_entity_q', 'nhk_entity_alias'] as $name) if (!in_array($name, $vars, true)) $vars[] = $name; return $vars; });
        add_action('init', [$this, 'rewrite']);
        add_action('template_redirect', [$this, 'legacyDetailRedirect'], 1);
        add_filter('template_include', [$this, 'template']);
    }

    public function rewrite(): void
    {
        foreach ($this->types->all() as $definition) {
            $type = preg_quote($definition->type, '#');
            add_rewrite_rule('^' . $type . '/page/([1-9][0-9]*)/?$', 'index.php?nhk_entity_type=' . $definition->type . '&nhk_entity_page=$matches[1]', 'top');
            add_rewrite_rule('^' . $type . '/([^/]+)/?$', 'index.php?nhk_entity_type=' . $definition->type . '&nhk_entity_key=$matches[1]', 'top');
            add_rewrite_rule('^' . $type . '/?$', 'index.php?nhk_entity_type=' . $definition->type, 'top');
        }
        foreach (self::LEGACY_ARCHIVE_ALIASES as $alias => $type) {
            add_rewrite_rule('^' . preg_quote($alias, '#') . '/page/([1-9][0-9]*)/?$', 'index.php?nhk_entity_type=' . $type . '&nhk_entity_alias=' . $alias . '&nhk_entity_page=$matches[1]', 'top');
            add_rewrite_rule('^' . preg_quote($alias, '#') . '/?$', 'index.php?nhk_entity_type=' . $type . '&nhk_entity_alias=' . $alias, 'top');
        }
    }

    public function template(string $template): string
    {
        $type = (string) get_query_var('nhk_entity_type');
        if ($type === '' || !$this->types->has($type)) return $template;
        $key = (string) get_query_var('nhk_entity_key');
        if ($key !== '') {
            $entity = $this->query->detail($type, rawurldecode($key));
            if ($entity === null) { $this->set404(); return get_404_template(); }
            $GLOBALS['nhk_core_entity_context'] = ['mode' => 'detail', 'type' => $type, 'entity' => $entity, 'archive_url' => home_url('/' . $type . '/')];
        } else {
            $page = max(1, (int) get_query_var('nhk_entity_page', 1)); $query = trim((string) get_query_var('nhk_entity_q'));
            $GLOBALS['nhk_core_entity_context'] = ['mode' => 'archive', 'type' => $type, 'archive' => $this->query->archive($type, $page, 24, $query), 'archive_url' => home_url('/' . $type . '/')];
        }
        $themeTemplate = locate_template('entity.php');
        return $themeTemplate !== '' ? $themeTemplate : $template;
    }

    private function set404(): void { global $wp_query; if (isset($wp_query) && is_object($wp_query)) $wp_query->set_404(); status_header(404); nocache_headers(); }

    public function legacyDetailRedirect(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli' || (string) get_query_var('nhk_entity_type') !== '' || !is_404()) return;
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($path)) return;
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        if (count($segments) === 1) {
            $key = $this->query->stableKeyForPublicSlug('brand', rawurldecode($segments[0]));
            if ($key !== null) $this->redirectCanonical('brand', $key);
        }
        if (count($segments) === 2) {
            $parent = $this->query->stableKeyForPublicSlug('brand', rawurldecode($segments[0]));
            $key = $this->query->stableKeyForPublicSlug('model', rawurldecode($segments[1]));
            if ($parent !== null && $key !== null) $this->redirectCanonical('model', $key);
        }
    }

    private function redirectCanonical(string $type, string $stableKey): void
    {
        wp_safe_redirect(home_url('/' . $type . '/' . rawurlencode($stableKey) . '/'), 301, 'NHK V2 entity compatibility');
        exit;
    }
}
