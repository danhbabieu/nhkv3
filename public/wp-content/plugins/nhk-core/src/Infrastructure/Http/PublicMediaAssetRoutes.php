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
            if (!in_array('nhk_media_audio_asset_key', $vars, true)) $vars[] = 'nhk_media_audio_asset_key';
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
        add_rewrite_rule('^am-thanh/([0-9A-Fa-f-]{36})/?$', 'index.php?nhk_media_audio_asset_key=$matches[1]', 'top');
        add_rewrite_rule('^anh/([^/]+\.webp)/?$', 'index.php?nhk_media_asset_filename=$matches[1]', 'top');
    }

    public function serve(): void
    {
        $assetKey = (string) get_query_var('nhk_media_asset_key');
        $audioAssetKey = (string) get_query_var('nhk_media_audio_asset_key');
        $filename = (string) get_query_var('nhk_media_asset_filename');
        if ($audioAssetKey !== '') {
            $response = $this->responseForAudioAsset(rawurldecode($audioAssetKey));
            if ($response === null) { $this->notFound(); return; }
            header('Content-Type: ' . $response['content_type']);
            header('Content-Length: ' . $response['size']);
            header('Content-Disposition: inline');
            header('Cache-Control: public, max-age=31536000, immutable');
            header('Accept-Ranges: bytes');
            header('X-Robots-Tag: noindex, nofollow');
            header('X-Content-Type-Options: nosniff');
            $rangeHeader = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
            $range = $this->byteRange($rangeHeader, $response['size']);
            if ($rangeHeader !== '' && $range === null) {
                status_header(416);
                header('Content-Range: bytes */' . $response['size']);
                exit;
            }
            if ($range !== null) {
                [$start, $end] = $range;
                status_header(206);
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $response['size']);
                header('Content-Length: ' . ($end - $start + 1));
                $handle = fopen($response['path'], 'rb');
                if (is_resource($handle)) {
                    fseek($handle, $start);
                    $remaining = $end - $start + 1;
                    while ($remaining > 0 && !feof($handle)) {
                        $chunk = fread($handle, min(8192, $remaining));
                        if (!is_string($chunk) || $chunk === '') break;
                        echo $chunk;
                        $remaining -= strlen($chunk);
                    }
                    fclose($handle);
                }
            } else {
                status_header(200);
                readfile($response['path']);
            }
            exit;
        }
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

        $response = $this->responseForFilename(rawurldecode($filename));
        if ($response === null) {
            $this->notFound();
            return;
        }
        header('Content-Type: ' . $response['content_type']);
        header('Content-Length: ' . $response['size']);
        header('Content-Disposition: inline');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        readfile($response['path']);
        exit;
    }

    /** @return array{status:int,content_type:string,size:int,path:string}|null */
    public function responseForFilename(string $filename): ?array
    {
        $resolved = $this->delivery->resolveByPublicFilename($filename);
        if ($resolved === null) return null;
        $size = filesize($resolved['path']);
        if ($size === false) return null;
        return ['status' => 200, 'content_type' => $resolved['asset']->mimeType, 'size' => $size, 'path' => $resolved['path']];
    }

    /** @return array{status:int,content_type:string,size:int,path:string}|null */
    public function responseForAudioAsset(string $assetId): ?array
    {
        $resolved = $this->delivery->resolveAudio($assetId);
        if ($resolved === null) return null;
        $size = filesize($resolved['path']);
        if ($size === false) return null;
        return ['status' => 200, 'content_type' => strtolower($resolved['asset']->mimeType), 'size' => $size, 'path' => $resolved['path']];
    }

    /** @return array{0:int,1:int}|null */
    public function byteRange(string $header, int $size): ?array
    {
        if ($size < 1 || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches)) return null;
        $startText = $matches[1];
        $endText = $matches[2];
        if ($startText === '' && $endText === '') return null;
        if ($startText === '') {
            $suffix = (int) $endText;
            if ($suffix < 1) return null;
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int) $startText;
            if ($start >= $size) return null;
            $end = $endText === '' ? $size - 1 : min($size - 1, (int) $endText);
            if ($end < $start) return null;
        }
        return [$start, $end];
    }

    public function legacyAssetRedirectTarget(string $assetKey): ?string
    {
        $resolved = $this->delivery->resolve($assetKey);
        if ($resolved === null) return null;
        $asset = $this->delivery->canonicalAsset($resolved['asset']);
        if ($asset === null) return null;
        $filename = trim((string) ($asset->metadata['canonical_filename'] ?? ''));
        if ($filename === '') return null;
        $target = (new PublicMediaAssetUrlResolver())->path($filename);
        return str_starts_with($target, '/anh/') ? $target : null;
    }

    private function notFound(): void
    {
        status_header(404);
        nocache_headers();
        exit;
    }
}
