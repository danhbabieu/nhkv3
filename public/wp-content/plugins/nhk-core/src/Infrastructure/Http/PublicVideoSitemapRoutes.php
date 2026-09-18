<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Video\VideoSitemapProjection;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Shared\Migration\MigrationStatus;

final class PublicVideoSitemapRoutes
{
    // Native wp-sitemap.xml remains independent; this endpoint is Video-only.
    public function __construct(private VideoRepository $videos, private ?MigrationStatus $status = null)
    {
    }

    public function register(): void
    {
        add_action('init', static function (): void { add_rewrite_rule('^video-sitemap\.xml$', 'index.php?nhk_video_sitemap=1', 'top'); });
        add_filter('query_vars', static function (array $vars): array { if (!in_array('nhk_video_sitemap', $vars, true)) $vars[] = 'nhk_video_sitemap'; return $vars; });
        add_action('template_redirect', [$this, 'render'], 1);
    }

    public function render(): void
    {
        if ((string) get_query_var('nhk_video_sitemap') !== '1' || ($this->status !== null && !$this->status->videoStorageReady())) return;
        $base = function_exists('home_url') ? (string) home_url('/') : '';
        $items = (new VideoSitemapProjection())->project($this->videos->list(), $base);
        header('Content-Type: application/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">';
        foreach ($items as $item) {
            echo '<url><loc>' . esc_xml($item['loc']) . '</loc><video:video><video:thumbnail_loc>' . esc_xml($item['thumbnail_url']) . '</video:thumbnail_loc><video:title>' . esc_xml($item['title']) . '</video:title><video:description>' . esc_xml($item['description']) . '</video:description><video:player_loc allow_embed="yes">' . esc_xml($item['loc']) . '</video:player_loc></video:video></url>';
        }
        echo '</urlset>';
        exit;
    }
}
