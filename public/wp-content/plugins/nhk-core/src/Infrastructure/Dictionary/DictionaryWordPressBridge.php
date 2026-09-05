<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Dictionary;

use NHK\Core\Application\Dictionary\{DictionaryHtmlLinker, DictionaryRuntime};
use NHK\Core\Infrastructure\Http\PublicDictionaryRoutes;

final class DictionaryWordPressBridge
{
    public function __construct(private DictionaryRuntime $runtime) {}

    public function register(): void
    {
        if ($this->runtime->available()) {
            (new PublicDictionaryRoutes($this->runtime->publicQuery()))->register();
            add_action('wp_sitemaps_init', function (object $sitemaps): void {
                if (isset($sitemaps->registry) && is_object($sitemaps->registry) && method_exists($sitemaps->registry, 'add_provider')) $sitemaps->registry->add_provider('dictionary', new WordPressDictionarySitemapProvider($this->runtime->publicQuery()));
            });
            add_filter('nhk_v3_search_semantic_results', [$this, 'extendSearch'], 20, 3);
            add_filter('the_content', [$this, 'linkContent'], 20);
        }
        add_action('wp_after_insert_post', [$this, 'observePost'], 40, 3);
        add_action('add_attachment', [$this, 'observeAttachment'], 40, 1);
        add_action('edit_attachment', [$this, 'observeAttachment'], 40, 1);
    }

    public function preview(string $text, array $context = []): array
    {
        return $this->runtime->preview($text, 'ARTICLE', '', $context);
    }

    public function observe(string $sourceKind, string $sourceId, string $text, array $context = [], array $hints = []): array
    {
        if (!$this->runtime->available() || trim($text) === '' || trim($sourceId) === '') return ['status' => 'UNAVAILABLE', 'blocking' => false];
        return $this->runtime->plan($text, $sourceKind, $sourceId, $context, $hints);
    }

    public function observePost(int $postId, \WP_Post $post, bool $update): void
    {
        if (!$this->runtime->available() || $post->post_type !== 'post' || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) return;
        $text = implode("\n", array_filter([(string) $post->post_title, (string) $post->post_excerpt, wp_strip_all_tags((string) $post->post_content)]));
        try { $this->observe('ARTICLE', (string) $postId, $text, ['post_id' => $postId, 'post_status' => (string) $post->post_status]); }
        catch (\Throwable $error) { do_action('nhk_v3_dictionary_observation_failure', 'ARTICLE', (string) $postId, $error->getMessage()); }
    }

    public function observeAttachment(int $attachmentId): void
    {
        if (!$this->runtime->available()) return;
        $post = get_post($attachmentId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') return;
        $alt = (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true);
        $filename = function_exists('get_attached_file') ? basename((string) get_attached_file($attachmentId)) : '';
        $text = implode("\n", array_filter([(string) $post->post_title, (string) $post->post_excerpt, (string) $post->post_content, $alt, $filename]));
        try { $this->observe('MEDIA', (string) $attachmentId, $text, ['attachment_id' => $attachmentId, 'weak_sources' => ['title', 'alt', 'filename']]); }
        catch (\Throwable $error) { do_action('nhk_v3_dictionary_observation_failure', 'MEDIA', (string) $attachmentId, $error->getMessage()); }
    }

    public function extendSearch(array $groups, string $term, int $page = 1): array
    {
        if (!$this->runtime->available()) { $groups['_availability']['dictionary'] = 'UNAVAILABLE'; return $groups; }
        $term = trim($term);
        $items = [];
        if ($term !== '') foreach ((array) ($this->runtime->publicQuery()->hub(2000)['items'] ?? []) as $item) {
            if (!is_array($item) || empty($item['url'])) continue;
            $haystack = [(string) ($item['title'] ?? ''), (string) ($item['description'] ?? '')];
            foreach ((array) ($item['labels'] ?? []) as $label) if (is_array($label)) $haystack[] = (string) ($label['label'] ?? '');
            if (!$this->matches($term, $haystack)) continue;
            $items[] = ['type' => 'dictionary', 'title' => (string) $item['title'], 'url' => (string) $item['url'], 'description' => (string) ($item['description'] ?? ''), 'term_type' => (string) ($item['term_type'] ?? '')];
        }
        $groups['dictionary'] = $items;
        $groups['_totals']['dictionary'] = count($items);
        $groups['_availability']['dictionary'] = 'AVAILABLE';
        return $groups;
    }

    public function linkContent(string $content): string
    {
        if (!$this->runtime->available() || !function_exists('is_singular') || !is_singular('post')) return $content;
        if (function_exists('in_the_loop') && !in_the_loop()) return $content;
        if (function_exists('is_main_query') && !is_main_query()) return $content;
        $current = function_exists('get_permalink') ? (string) get_permalink() : '';
        $terms = array_values(array_filter($this->runtime->publicTerms(), static fn (array $term): bool => $current === '' || rtrim((string) $term['url'], '/') !== rtrim($current, '/')));
        return (new DictionaryHtmlLinker())->link($content, $terms);
    }

    private function matches(string $term, array $values): bool
    {
        foreach ($values as $value) if ((function_exists('mb_stripos') ? mb_stripos((string) $value, $term) : stripos((string) $value, $term)) !== false) return true;
        return false;
    }
}
