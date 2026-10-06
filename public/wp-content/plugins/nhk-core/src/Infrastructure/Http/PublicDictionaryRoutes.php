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
            $query = isset($_GET['q']) && is_string($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
            $initial = isset($_GET['initial']) && is_string($_GET['initial']) ? sanitize_text_field(wp_unslash($_GET['initial'])) : '';
            $cursor = isset($_GET['cursor']) && is_string($_GET['cursor']) ? sanitize_text_field(wp_unslash($_GET['cursor'])) : null;
            $packet = $this->query->archive(['query' => $query, 'initial' => $initial, 'page_size' => 500, 'cursor' => $cursor]);
            if (!in_array(($packet['status'] ?? ''), ['AVAILABLE', 'EMPTY'], true)) {
                $this->set404();
                return get_404_template();
            }
            $GLOBALS['nhk_core_dictionary_context'] = ['mode' => 'hub', 'result' => $packet];
        }

        // The active theme owns the public presentation; the plugin file is a portability fallback.
        $theme = locate_template('dictionary.php', false, false);
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
            $seo = is_array($result['seo'] ?? null) ? $result['seo'] : [];
            $canonical = $this->absolute((string) ($seo['canonical'] ?? $result['canonical_url'] ?? ''));
            if ($canonical !== '') echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
            if (($seo['robots'] ?? '') !== '') echo '<meta name="robots" content="' . esc_attr((string) $seo['robots']) . '" />' . "\n";
            if (($seo['sitemap'] ?? true) === false) echo '<meta name="nhk-dictionary-sitemap" content="exclude" />' . "\n";
            if (($seo['state'] ?? '') === 'REDIRECT') return;
            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '') {
                $senseDescriptions = [];
                foreach ((array) ($item['senses'] ?? []) as $sense) if (is_array($sense) && trim((string) ($sense['description'] ?? '')) !== '') $senseDescriptions[] = trim((string) $sense['description']);
                $description = implode(' ', array_slice($senseDescriptions, 0, 6));
            }
            $schema = ['@context' => 'https://schema.org', '@type' => 'DefinedTerm', 'name' => (string) ($item['title'] ?? ''), 'description' => $description, 'url' => $canonical, 'inDefinedTermSet' => $this->absolute('/tu-dien/')];
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
