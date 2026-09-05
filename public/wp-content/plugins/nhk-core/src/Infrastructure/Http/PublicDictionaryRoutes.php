<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Dictionary\DictionaryPublicQuery;

final class PublicDictionaryRoutes
{
    public function __construct(private DictionaryPublicQuery $query) {}

    public function register(): void
    {
        add_filter('query_vars', function (array $vars): array { foreach (['nhk_dictionary_hub', 'nhk_dictionary_slug'] as $name) if (!in_array($name, $vars, true)) $vars[] = $name; return $vars; });
        add_action('init', [$this, 'rewrite']);
        add_filter('template_include', [$this, 'template']);
        add_action('wp_head', [$this, 'head'], 2);
    }

    public function rewrite(): void
    {
        add_rewrite_rule('^tu-dien/?$', 'index.php?nhk_dictionary_hub=1', 'top');
        add_rewrite_rule('^tu-dien/([^/]+)/?$', 'index.php?nhk_dictionary_slug=$matches[1]', 'top');
    }

    public function template(string $template): string
    {
        $slug = (string) get_query_var('nhk_dictionary_slug');
        $hub = (string) get_query_var('nhk_dictionary_hub');
        if ($slug === '' && $hub === '') return $template;

        if ($slug !== '') {
            $result = $this->query->detail($slug);
            if (($result['status'] ?? '') === 'REDIRECT' && trim((string) ($result['destination_url'] ?? '')) !== '') {
                wp_safe_redirect($this->absolute((string) $result['destination_url']), 301, 'NHK V3 Dictionary');
                exit;
            }
            if (($result['status'] ?? '') !== 'READY') {
                $this->set404();
                return get_404_template();
            }
            $GLOBALS['nhk_core_dictionary_context'] = ['mode' => 'detail', 'result' => $result];
        } else {
            $packet = $this->query->hub();
            if (($packet['status'] ?? '') !== 'AVAILABLE') {
                $this->set404();
                return get_404_template();
            }
            $GLOBALS['nhk_core_dictionary_context'] = ['mode' => 'hub', 'result' => $packet];
        }

        $theme = locate_template('dictionary.php');
        if ($theme !== '') return $theme;
        return dirname(__DIR__, 3) . '/templates/dictionary.php';
    }

    public function head(): void
    {
        $context = $GLOBALS['nhk_core_dictionary_context'] ?? null;
        if (!is_array($context) || !is_array($context['result'] ?? null)) return;
        $result = $context['result'];
        $mode = (string) ($context['mode'] ?? '');
        if ($mode === 'detail') {
            $item = is_array($result['item'] ?? null) ? $result['item'] : [];
            $canonical = $this->absolute((string) ($result['canonical_url'] ?? ''));
            if ($canonical !== '') echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
            $schema = ['@context' => 'https://schema.org', '@type' => 'DefinedTerm', 'name' => (string) ($item['title'] ?? ''), 'description' => (string) ($item['description'] ?? ''), 'url' => $canonical, 'inDefinedTermSet' => $this->absolute('/tu-dien/')];
            echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
            return;
        }
        if ($mode === 'hub') {
            $canonical = $this->absolute('/tu-dien/');
            echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
            $terms = [];
            foreach ((array) ($result['items'] ?? []) as $item) {
                if (!is_array($item) || trim((string) ($item['url'] ?? '')) === '') continue;
                $terms[] = ['@type' => 'DefinedTerm', 'name' => (string) ($item['title'] ?? ''), 'url' => $this->absolute((string) $item['url'])];
            }
            $schema = ['@context' => 'https://schema.org', '@type' => 'DefinedTermSet', 'name' => 'Từ điển đồng hồ cổ', 'url' => $canonical, 'hasDefinedTerm' => $terms];
            echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        }
    }

    private function absolute(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (preg_match('#^https?://#i', $url)) return $url;
        return function_exists('home_url') ? (string) home_url('/' . ltrim($url, '/')) : $url;
    }

    private function set404(): void
    {
        global $wp_query;
        if (isset($wp_query) && is_object($wp_query)) $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
}
