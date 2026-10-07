<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Dictionary\DictionaryPublicQuery;
use NHK\Core\Application\Seo\PublicSeoProjection;

final class PublicDictionaryRoutes
{
    public function __construct(private DictionaryPublicQuery $query) {}

    public function register(): void
    {
        add_filter('query_vars', function (array $vars): array { foreach (['nhk_dictionary_hub', 'nhk_dictionary_slug'] as $name) if (!in_array($name, $vars, true)) $vars[] = $name; return $vars; });
        add_action('init', [$this, 'rewrite']);
        add_filter('template_include', [$this, 'template']);
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
            $GLOBALS['nhk_core_dictionary_context'] = ['mode' => 'detail', 'result' => $result, 'seo_projection' => $result['seo_projection'] ?? []];
        } else {
            $query = isset($_GET['q']) && is_string($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
            $initial = isset($_GET['initial']) && is_string($_GET['initial']) ? sanitize_text_field(wp_unslash($_GET['initial'])) : '';
            $cursor = isset($_GET['cursor']) && is_string($_GET['cursor']) ? sanitize_text_field(wp_unslash($_GET['cursor'])) : null;
            $packet = $this->query->archive(['query' => $query, 'initial' => $initial, 'page_size' => 24, 'cursor' => $cursor]);
            if (!in_array(($packet['status'] ?? ''), ['AVAILABLE', 'EMPTY'], true)) {
                $this->set404();
                return get_404_template();
            }
            $hubSeo = (new PublicSeoProjection())->project(['path' => '/tu-dien/', 'eligible' => true], ['title' => 'Từ điển đồng hồ cổ — Đồng Hồ Nhà Kho', 'description' => 'Tra cứu thuật ngữ kỹ thuật, tên gọi quốc tế và cách gọi trong giới sưu tầm đồng hồ.', 'type' => 'DefinedTermSet']);
            if ($query !== '' || $initial !== '' || $cursor !== null) {
                $hubSeo['indexable'] = false; $hubSeo['sitemap'] = false; $hubSeo['robots'] = 'noindex,follow'; $hubSeo['json_ld'] = [];
            }
            $packet['seo_projection'] = $hubSeo;
            $GLOBALS['nhk_core_dictionary_context'] = ['mode' => 'hub', 'result' => $packet, 'seo_projection' => $hubSeo];
        }

        $theme = locate_template('dictionary.php', false, false);
        return $theme !== '' ? $theme : dirname(__DIR__, 3) . '/templates/dictionary.php';
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
