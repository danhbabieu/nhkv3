<?php
declare(strict_types=1);

final class NHK_V3_Home_Page_Query
{
    /** @return array{featured:list<WP_Post>,latest:list<WP_Post>,sidebar:list<WP_Post>,sections:list<array<string,mixed>>,topics:list<WP_Term>,semantic:array<string,list<array<string,mixed>>>} */
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
        return ['featured' => $featured, 'latest' => $latest, 'sidebar' => array_slice($latest, 0, 4), 'sections' => $sections, 'topics' => $topics, 'semantic' => is_array($semantic) ? $semantic : ['entities' => [], 'media' => [], 'videos' => []]];
    }

    /** @return list<WP_Post> */
    private function posts(array $args): array
    {
        return (new WP_Query(array_merge(['post_type' => 'post', 'post_status' => 'publish'], $args))->posts ?: []);
    }
}
