<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Entity\EntityPageQuery;
use NHK\Core\Domain\Authority\EntityTypeRegistry;

final class PublicEntityRoutes
{
    public function __construct(private EntityPageQuery $query, private EntityTypeRegistry $types) {}

    public function register(): void
    {
        add_filter('query_vars', function (array $vars): array { foreach (['nhk_entity_type', 'nhk_entity_key', 'nhk_entity_page', 'nhk_entity_q'] as $name) if (!in_array($name, $vars, true)) $vars[] = $name; return $vars; });
        add_action('init', [$this, 'rewrite']);
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
            $GLOBALS['nhk_core_entity_context'] = ['mode' => 'archive', 'archive' => $this->query->archive($type, $page, 24, $query), 'archive_url' => home_url('/' . $type . '/')];
        }
        $themeTemplate = locate_template('entity.php');
        return $themeTemplate !== '' ? $themeTemplate : $template;
    }

    private function set404(): void { global $wp_query; if (isset($wp_query) && is_object($wp_query)) $wp_query->set_404(); status_header(404); nocache_headers(); }
}
