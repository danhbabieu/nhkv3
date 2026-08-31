<?php
declare(strict_types=1);

final class NHK_V3_Search_Page_Query
{
    /** @return array{term:string,total:int,posts:list<WP_Post>,semantic:array<string,list<array<string,mixed>>>} */
    public function read(): array
    {
        $term = trim(get_search_query());
        $query = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 's' => $term, 'posts_per_page' => 12, 'paged' => max(1, (int) get_query_var('paged', 1)), 'ignore_sticky_posts' => true]);
        $groups = apply_filters('nhk_v3_search_semantic_results', ['entities' => [], 'media' => [], 'videos' => [], 'knowledge' => []], $term);
        return ['term' => $term, 'total' => (int) $query->found_posts, 'posts' => $query->posts ?: [], 'semantic' => is_array($groups) ? $groups : ['entities' => [], 'media' => [], 'videos' => [], 'knowledge' => []]];
    }
}
