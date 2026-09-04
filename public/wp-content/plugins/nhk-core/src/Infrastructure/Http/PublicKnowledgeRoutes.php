<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Knowledge\KnowledgePageQuery;

final class PublicKnowledgeRoutes
{
    public function __construct(private KnowledgePageQuery $query) {}
    public static function atomicDetailIsPublic(): bool { return false; }
    public static function publicAtomicDetail(?KnowledgePageQuery $query, string $key): ?array { return null; }

    public function register(): void
    {
        add_filter('query_vars', function (array $vars): array { foreach (['nhk_knowledge_key', 'nhk_knowledge_slug', 'nhk_knowledge_page'] as $name) if (!in_array($name, $vars, true)) $vars[] = $name; return $vars; });
        add_action('init', [$this, 'rewrite']);
        add_filter('template_include', [$this, 'template']);
    }

    public function rewrite(): void
    {
        add_rewrite_rule('^knowledge/page/([1-9][0-9]*)/?$', 'index.php?nhk_knowledge_page=$matches[1]', 'top');
        add_rewrite_rule('^knowledge/?$', 'index.php?nhk_knowledge_page=1', 'top');
    }

    public function template(string $template): string
    {
        $key = (string) get_query_var('nhk_knowledge_key');
        $slug = (string) get_query_var('nhk_knowledge_slug');
        $page = get_query_var('nhk_knowledge_page');
        if ($slug !== '' || $key !== '') {
            $this->set404();
            return get_404_template();
        } elseif ($page !== '') {
            $GLOBALS['nhk_core_knowledge_context'] = ['mode' => 'archive', 'archive' => $this->query->archive(max(1, (int) $page)), 'archive_url' => home_url('/tri-thuc/')];
        } else return $template;
        $found = locate_template('knowledge.php');
        return $found !== '' ? $found : $template;
    }

    private function set404(): void { global $wp_query; if (isset($wp_query) && is_object($wp_query)) $wp_query->set_404(); status_header(404); nocache_headers(); }
}
