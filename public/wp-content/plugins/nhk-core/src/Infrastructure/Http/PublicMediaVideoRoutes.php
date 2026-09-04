<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Media\MediaVideoPageQuery;
use NHK\Core\Application\PublicIdentity\HistoricPublicRouteService;

final class PublicMediaVideoRoutes
{
    public function __construct(private ?MediaVideoPageQuery $query, private ?HistoricPublicRouteService $historic = null) {}
    public static function mediaDetailIsPublic(): bool { return false; }
    public static function mediaDetailGateReason(): string { return 'CONSTITUTION_CONFLICT'; }
    public static function publicMediaDetail(?MediaVideoPageQuery $query, string $slug): ?array { return null; }
    public static function assetDeliveryPath(string $assetId): string { return '/media/asset/' . rawurlencode($assetId) . '/'; }
    public function historicRedirect(string $path): array
    {
        $result = $this->historic?->resolveHistoric($path) ?? ['status' => 'NOT_FOUND'];
        return ($result['status'] ?? '') === 'FOUND' ? ['status' => 301, 'location' => (string)$result['target']] : ['status' => 404];
    }
    public function register(): void { add_filter('query_vars', function (array $vars): array { foreach (['nhk_video_key', 'nhk_video_slug', 'nhk_video_page', 'nhk_media_key', 'nhk_media_slug', 'nhk_media_page', 'nhk_media_route'] as $name) if (!in_array($name, $vars, true)) $vars[] = $name; return $vars; }); add_action('init', [$this, 'rewrite']); add_action('template_redirect', [$this, 'historicVideoRedirect'], 1); add_action('template_redirect', [$this, 'legacyVideoRedirect'], 1); add_action('template_redirect', [$this, 'legacyMediaRedirect'], 1); add_filter('template_include', [$this, 'template']); }
    public function historicVideoRedirect(): void
    {
        if ($this->historic === null || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli' || !is_404()) return;
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/video/')) return;
        $result = $this->historicRedirect($path);
        if ($result['status'] !== 301) return;
        wp_safe_redirect(home_url((string) $result['location']), 301, 'NHK historic public route'); exit;
    }
    public function rewrite(): void { add_rewrite_rule('^video/page/([1-9][0-9]*)/?$', 'index.php?nhk_video_page=$matches[1]', 'top'); add_rewrite_rule('^video/([a-z0-9_-]+)/?$', 'index.php?nhk_video_slug=$matches[1]', 'top'); add_rewrite_rule('^video/?$', 'index.php?nhk_video_page=1', 'top'); add_rewrite_rule('^(?:thu-vien|media)/?$', 'index.php?nhk_media_route=archive&nhk_media_page=1', 'top'); add_rewrite_rule('^media/page/([1-9][0-9]*)/?$', 'index.php?nhk_media_route=archive&nhk_media_page=$matches[1]', 'top'); }
    public function template(string $template): string { $videoKey = (string) get_query_var('nhk_video_key'); $videoSlug = (string) get_query_var('nhk_video_slug'); if ($videoKey !== '' || $videoSlug !== '' || get_query_var('nhk_video_page') !== '') { if ($videoKey !== '' || $videoSlug !== '') { $video = $videoSlug !== '' ? $this->query->videoBySlug(rawurldecode($videoSlug)) : $this->query->videoDetail(rawurldecode($videoKey)); if ($video === null) return $this->notFound(); if ($videoKey !== '') { wp_safe_redirect(home_url((string) ($video['public_url'] ?? '/video/')), 301, 'NHK canonical video route'); exit; } $GLOBALS['nhk_core_video_context'] = ['mode' => 'detail', 'video' => $video]; } else $GLOBALS['nhk_core_video_context'] = ['mode' => 'archive', 'archive' => $this->query->videoArchive(max(1, (int) get_query_var('nhk_video_page', 1)))]; $found = locate_template('video.php'); return $found !== '' ? $found : $template; } $mediaRoute = (string) get_query_var('nhk_media_route'); if ($mediaRoute === '') return $template; if ((string) get_query_var('nhk_media_slug') !== '') return $this->notFound(); $GLOBALS['nhk_core_media_context'] = ['mode' => 'archive', 'archive' => $this->query->mediaArchive(max(1, (int) get_query_var('nhk_media_page', 1)))]; $found = locate_template('media.php'); return $found !== '' ? $found : $template; }
    public function legacyMediaRedirect(): void { if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli' || (string) get_query_var('nhk_media_key') === '') return; $media = $this->query->mediaDetail((string) get_query_var('nhk_media_key')); $target = is_array($media) ? (string) ($media['url'] ?? '') : ''; if ($target !== '') { wp_safe_redirect(home_url($target), 301, 'NHK canonical media route'); exit; } }
    public function legacyVideoRedirect(): void { if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli' || (string) get_query_var('nhk_video_key') === '') return; $video = $this->query->videoDetail((string) get_query_var('nhk_video_key')); $target = is_array($video) ? (string) ($video['public_url'] ?? '') : ''; if ($target !== '') { wp_safe_redirect(home_url($target), 301, 'NHK canonical video route'); exit; } }
    private function notFound(): string { global $wp_query; if (isset($wp_query) && is_object($wp_query)) $wp_query->set_404(); status_header(404); nocache_headers(); return get_404_template(); }
}
