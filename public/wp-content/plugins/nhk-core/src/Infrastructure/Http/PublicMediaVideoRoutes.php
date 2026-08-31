<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Media\MediaVideoPageQuery;

final class PublicMediaVideoRoutes
{
    public function __construct(private MediaVideoPageQuery $query) {}
    public function register(): void { add_filter('query_vars', function (array $vars): array { foreach (['nhk_video_key', 'nhk_video_page', 'nhk_media_key', 'nhk_media_page', 'nhk_media_route'] as $name) if (!in_array($name, $vars, true)) $vars[] = $name; return $vars; }); add_action('init', [$this, 'rewrite']); add_filter('template_include', [$this, 'template']); }
    public function rewrite(): void { add_rewrite_rule('^video/page/([1-9][0-9]*)/?$', 'index.php?nhk_video_page=$matches[1]', 'top'); add_rewrite_rule('^video/([0-9a-f-]{36})/?$', 'index.php?nhk_video_key=$matches[1]', 'top'); add_rewrite_rule('^video/?$', 'index.php?nhk_video_page=1', 'top'); add_rewrite_rule('^(?:thu-vien|media)/?$', 'index.php?nhk_media_route=archive&nhk_media_page=1', 'top'); add_rewrite_rule('^media/([0-9a-f-]{36})/?$', 'index.php?nhk_media_route=detail&nhk_media_key=$matches[1]', 'top'); add_rewrite_rule('^media/page/([1-9][0-9]*)/?$', 'index.php?nhk_media_route=archive&nhk_media_page=$matches[1]', 'top'); }
    public function template(string $template): string { $videoKey = (string) get_query_var('nhk_video_key'); if ($videoKey !== '' || get_query_var('nhk_video_page') !== '') { if ($videoKey !== '') { $video = $this->query->videoDetail(rawurldecode($videoKey)); if ($video === null) return $this->notFound(); $GLOBALS['nhk_core_video_context'] = ['mode' => 'detail', 'video' => $video]; } else $GLOBALS['nhk_core_video_context'] = ['mode' => 'archive', 'archive' => $this->query->videoArchive(max(1, (int) get_query_var('nhk_video_page', 1)))]; $found = locate_template('video.php'); return $found !== '' ? $found : $template; } $mediaRoute = (string) get_query_var('nhk_media_route'); if ($mediaRoute === '') return $template; if ($mediaRoute === 'detail') { $media = $this->query->mediaDetail(rawurldecode((string) get_query_var('nhk_media_key'))); if ($media === null) return $this->notFound(); $GLOBALS['nhk_core_media_context'] = ['mode' => 'detail', 'media' => $media]; } else $GLOBALS['nhk_core_media_context'] = ['mode' => 'archive', 'archive' => $this->query->mediaArchive(max(1, (int) get_query_var('nhk_media_page', 1)))]; $found = locate_template('media.php'); return $found !== '' ? $found : $template; }
    private function notFound(): string { global $wp_query; if (isset($wp_query) && is_object($wp_query)) $wp_query->set_404(); status_header(404); nocache_headers(); return get_404_template(); }
}
