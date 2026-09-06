<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Media\{PublicMediaAssetDelivery, PublicMediaAssetUrlResolver};

final class PublicMediaAssetRoutes
{
    public function __construct(private PublicMediaAssetDelivery $delivery) {}

    public function register(): void
    {
        add_filter('query_vars', function (array $vars): array {
            if (!in_array('nhk_media_asset_key', $vars, true)) $vars[] = 'nhk_media_asset_key';
            if (!in_array('nhk_media_asset_filename', $vars, true)) $vars[] = 'nhk_media_asset_filename';
            return $vars;
        });
        add_action('init', [$this, 'rewrite']);
        add_action('template_redirect', [$this, 'serve'], 0);
    }

    public function rewrite(): void
    {
        // Technical UUID route is retained only as a legacy redirect input.
        add_rewrite_rule('^media/asset/([0-9A-Fa-f-]{36})/?$', 'index.php?nhk_media_asset_key=$matches[1]', 'top');
        add_rewrite_rule('^anh/([^/]+\.webp)/?$', 'index.php?nhk_media_asset_filename=$matches[1]', 'top');
    }

    public function serve(): void
    {
        $assetKey = (string) get_query_var('nhk_media_asset_key');
        $filename = (string) get_query_var('nhk_media_asset_filename');
        if ($assetKey === '' && $filename === '') return;

        if ($assetKey !== '') {
            $target = $this->legacyAssetRedirectTarget(rawurldecode($assetKey));
            if ($target === null) {
                $this->notFound();
                return;
            }
            $location = function_exists('home_url') ? home_url($target) : $target;
            wp_safe_redirect($location, 301);
            exit;
        }

        $resolved = $this->delivery->resolveByPublicFilename(rawurldecode($filename));
        if ($resolved === null) {
            $this->notFound();
            return;
        }
        $asset = $resolved['asset'];
        $size = filesize($resolved['path']);
        if ($size === false) {
            $this->notFound();
            return;
        }
        header('Content-Type: ' . $asset->mimeType);
        header('Content-Length: ' . $size);
        header('Content-Disposition: inline');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        readfile($resolved['path']);
        exit;
    }

    public function legacyAssetRedirectTarget(string $assetKey): ?string
    {
        $resolved = $this->delivery->resolve($assetKey);
        if ($resolved === null) return null;
        $asset = $resolved['asset'];
        $filename = trim((string) ($asset->metadata['canonical_filename'] ?? ''));
        if ($filename === '') return null;
        $target = (new PublicMediaAssetUrlResolver())->path($filename);
        return str_starts_with($target, '/anh/') ? $target : null;
    }

    private function notFound(): void
    {
        status_header(404);
        nocache_headers();
    }
}
