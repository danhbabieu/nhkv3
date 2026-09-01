<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Media\PublicMediaAssetDelivery;

final class PublicMediaAssetRoutes
{
    public function __construct(private PublicMediaAssetDelivery $delivery) {}

    public function register(): void
    {
        add_filter('query_vars', function (array $vars): array {
            if (!in_array('nhk_media_asset_key', $vars, true)) $vars[] = 'nhk_media_asset_key';
            return $vars;
        });
        add_action('init', [$this, 'rewrite']);
        add_action('template_redirect', [$this, 'serve'], 0);
    }

    public function rewrite(): void
    {
        add_rewrite_rule('^media/asset/([0-9A-Fa-f-]{36})/?$', 'index.php?nhk_media_asset_key=$matches[1]', 'top');
    }

    public function serve(): void
    {
        $assetKey = (string) get_query_var('nhk_media_asset_key');
        if ($assetKey === '') return;
        $resolved = $this->delivery->resolve(rawurldecode($assetKey));
        if ($resolved === null) {
            status_header(404);
            nocache_headers();
            return;
        }
        $asset = $resolved['asset'];
        $size = filesize($resolved['path']);
        if ($size === false) {
            status_header(404);
            nocache_headers();
            return;
        }
        header('Content-Type: ' . $asset->mimeType);
        header('Content-Length: ' . $size);
        header('Content-Disposition: inline');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        readfile($resolved['path']);
        exit;
    }
}
