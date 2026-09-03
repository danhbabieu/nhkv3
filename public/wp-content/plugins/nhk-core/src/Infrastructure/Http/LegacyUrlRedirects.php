<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

final class LegacyUrlRedirects
{
    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'redirect'], 1);
        add_filter('redirect_canonical', [self::class, 'canonicalRedirect'], 10, 2);
    }

    public static function redirect(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli') return;
        $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($requestPath) || $requestPath === '') return;
        $requestPath = '/' . trim($requestPath, '/') . '/';
        if (self::shouldDeferForSemanticRoot($requestPath, (string) get_query_var('nhk_public_entity_type'))) return;
        $entityRedirects = get_option('nhk_v2_entity_redirects', []);
        if (is_array($entityRedirects) && isset($entityRedirects[$requestPath])) {
            $targetPath = trim((string) $entityRedirects[$requestPath]);
            if (str_starts_with($targetPath, '/') && !str_contains($targetPath, '..') && rtrim('/' . trim($targetPath, '/'), '/') !== rtrim($requestPath, '/')) {
                wp_safe_redirect(home_url($targetPath), 301, 'NHK V2 URL migration');
                exit;
            }
        }
        $posts = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'any', 'meta_key' => '_nhk_v2_redirect_path', 'meta_value' => $requestPath, 'numberposts' => 1]);
        if (!$posts) return;
        $target = get_permalink((int) $posts[0]->ID);
        if (!is_string($target) || $target === '') return;
        $targetPath = parse_url($target, PHP_URL_PATH);
        if (is_string($targetPath) && rtrim('/' . trim($targetPath, '/'), '/') === rtrim($requestPath, '/')) return;
        wp_safe_redirect($target, 301, 'NHK V2 URL migration');
        exit;
    }

    public static function shouldDeferForSemanticRoot(string $requestPath, string $publicEntityType): bool
    {
        $segments = array_values(array_filter(explode('/', trim($requestPath, '/')), static fn (string $segment): bool => $segment !== ''));
        return count($segments) === 1 && $publicEntityType === 'brand';
    }

    public static function canonicalRedirect(?string $redirectUrl, string $requestedUrl): ?string
    {
        $requestPath = parse_url($requestedUrl, PHP_URL_PATH);
        if (!is_string($requestPath)) return $redirectUrl;
        return self::filterCanonicalRedirect($redirectUrl, $requestPath, (string) get_query_var('nhk_public_entity_type'));
    }

    public static function filterCanonicalRedirect(?string $redirectUrl, string $requestPath, string $publicEntityType): ?string
    {
        return self::shouldDeferForSemanticRoot($requestPath, $publicEntityType) ? null : $redirectUrl;
    }
}
