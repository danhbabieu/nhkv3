<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\WordPress;

/** Resolves one first-party permalink to one live native WordPress post. */
final class WordPressPostUrlResolver
{
    public function resolve(string $url): array
    {
        $parts = parse_url(trim($url));
        $home = function_exists('home_url') ? parse_url((string) home_url('/')) : false;
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !is_array($home)
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($home['host'] ?? ''))
            || isset($parts['user'], $parts['pass'], $parts['fragment'])) throw new \InvalidArgumentException('MEDIA_TARGET_URL_INVALID');
        if (!function_exists('url_to_postid') || !function_exists('get_post') || !function_exists('get_permalink')) throw new \InvalidArgumentException('MEDIA_TARGET_URL_UNAVAILABLE');
        $postId = (int) url_to_postid($url);
        $post = $postId > 0 ? get_post($postId) : null;
        if (!is_object($post) || (string) ($post->post_type ?? '') !== 'post' || (string) ($post->post_status ?? '') === 'trash') throw new \InvalidArgumentException('MEDIA_TARGET_NOT_FOUND');
        $canonical = (string) get_permalink($postId);
        if ($canonical === '' || rtrim($canonical, '/') !== rtrim($url, '/')) throw new \InvalidArgumentException('MEDIA_TARGET_URL_NOT_FOUND');
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        return ['type' => 'wp_post', 'id' => $blogId . ':' . $postId];
    }
}
