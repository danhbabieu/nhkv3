<?php
declare(strict_types=1);

final class NHK_V3_Home_Page_Query
{
    /** @return array{featured:list<WP_Post>,latest:list<WP_Post>,latest_feed:list<array<string,mixed>>,sidebar:list<WP_Post>,sections:list<array<string,mixed>>,topics:list<WP_Term>,semantic:array<string,list<array<string,mixed>>>} */
    public function read(): array
    {
        $latest = $this->posts(['posts_per_page' => 6, 'ignore_sticky_posts' => true]);
        $featured = $this->posts(['posts_per_page' => 3, 'post__in' => array_values(array_filter(array_map('intval', (array) get_option('sticky_posts')))), 'orderby' => 'post__in']);
        if ($featured === []) $featured = array_slice($latest, 0, 3);
        $sections = [];
        foreach ([['Tri thức', 'tri-thuc'], ['Góc chia sẻ', 'goc-chia-se']] as [$label, $slug]) {
            $category = get_category_by_slug($slug);
            if (!$category) continue;
            $posts = $this->posts(['cat' => $category->term_id, 'posts_per_page' => 3]);
            if ($posts === []) continue;
            $sections[] = ['label' => $label, 'slug' => $slug, 'url' => home_url('/' . $slug . '/'), 'posts' => $posts];
        }
        $topics = array_values(array_filter(get_categories(['hide_empty' => true, 'number' => 6, 'orderby' => 'count', 'order' => 'DESC']), static fn (WP_Term $term): bool => $term->count > 0));
        $semantic = apply_filters('nhk_v3_home_semantic_modules', ['entities' => [], 'media' => [], 'videos' => []]);
        $semantic = is_array($semantic) ? $semantic : ['entities' => [], 'media' => [], 'videos' => []];
        $latestFeed = is_array($semantic['latest_feed'] ?? null) ? $semantic['latest_feed'] : [];
        foreach ($latest as $post) {
            $image = has_post_thumbnail($post) ? wp_get_attachment_image_src((int) get_post_thumbnail_id($post), 'medium_large') : false;
            $width = is_array($image) ? (int) ($image[1] ?? 0) : 0; $height = is_array($image) ? (int) ($image[2] ?? 0) : 0;
            $latestFeed[] = ['type' => 'article', 'label' => 'Bài viết', 'title' => get_the_title($post), 'url' => get_permalink($post), 'timestamp' => get_post_time('c', true, $post), 'summary' => wp_trim_words(wp_strip_all_tags((string) ($post->post_excerpt ?: $post->post_content)), 24), 'image_url' => is_array($image) ? (string) ($image[0] ?? '') : null, 'orientation' => $height > $width ? 'portrait' : ($height === $width && $width > 0 ? 'square' : ($width > 0 ? 'landscape' : 'unknown')), 'width' => $width ?: null, 'height' => $height ?: null, 'tie_breaker' => 'post:' . (string) $post->ID];
        }
        if (class_exists('NHK\\Core\\Application\\Presentation\\LatestFirstOrder')) $latestFeed = \NHK\Core\Application\Presentation\LatestFirstOrder::sort($latestFeed, static fn (array $item): ?string => (string) ($item['timestamp'] ?? ''), static fn (array $item): ?string => null, static fn (array $item): string => (string) ($item['tie_breaker'] ?? ''));
        else usort($latestFeed, static function (array $a, array $b): int { $at = strtotime((string) ($a['timestamp'] ?? '')) ?: PHP_INT_MIN; $bt = strtotime((string) ($b['timestamp'] ?? '')) ?: PHP_INT_MIN; return $bt <=> $at ?: strcmp((string) ($b['tie_breaker'] ?? ''), (string) ($a['tie_breaker'] ?? '')); });
        foreach ($latestFeed as &$item) unset($item['tie_breaker']);
        return ['featured' => $featured, 'latest' => $latest, 'latest_feed' => array_slice($latestFeed, 0, 12), 'sidebar' => array_slice($latest, 0, 4), 'sections' => $sections, 'topics' => $topics, 'semantic' => $semantic];
    }

    /** @return list<WP_Post> */
    private function posts(array $args): array
    {
        return (new WP_Query(array_merge(['post_type' => 'post', 'post_status' => 'publish', 'orderby' => ['date' => 'DESC', 'ID' => 'DESC']], $args))->posts ?: []);
    }
}
