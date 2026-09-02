<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Article;

use NHK\Core\Contracts\Article\EditorialStateReader;
use NHK\Core\Domain\Article\EditorialPostState;

final class WpEditorialStateReader implements EditorialStateReader
{
    public function read(int $postId): ?EditorialPostState
    {
        if ($postId < 1 || !function_exists('get_post')) return null;
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) return null;
        $revision = function_exists('wp_get_latest_revision_id_and_total_count')
            ? wp_get_latest_revision_id_and_total_count($post)
            : ['latest_id' => 0, 'count' => 0];
        if (!is_array($revision)) $revision = ['latest_id' => 0, 'count' => 0];
        $permalink = function_exists('get_permalink') ? get_permalink($post) : '';
        $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 0;
        if ($blogId < 1 || !is_string($permalink)) return null;
        return new EditorialPostState(
            (int) $post->ID,
            $blogId . ':' . (int) $post->ID,
            (string) $post->post_type,
            (string) $post->post_status,
            (string) $post->post_title,
            (string) $post->post_content,
            (string) $post->post_excerpt,
            (string) $post->post_name,
            $permalink,
            (int) ($revision['latest_id'] ?? 0),
            (int) ($revision['count'] ?? 0),
            (string) ($post->post_modified_gmt ?? ''),
        );
    }
}
