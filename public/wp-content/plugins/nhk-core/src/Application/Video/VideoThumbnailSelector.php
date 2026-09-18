<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

/**
 * Selects a usable external thumbnail without creating a Media owner. The
 * probe is injected so the application edge can use its approved HTTP
 * boundary while projections can safely consume a persisted selection.
 */
final class VideoThumbnailSelector
{
    private const PRIORITY = ['maxresdefault', 'sddefault', 'hqdefault', 'mqdefault', 'default'];
    private const COMPACT_PRIORITY = ['mqdefault', 'hqdefault', 'sddefault', 'maxresdefault', 'default'];

    /** @param callable(string):array<string,mixed>|null $probe */
    public function __construct(private $probe = null)
    {
    }

    /** @return array<string,mixed>|null */
    public static function wordpressProbe(string $url): ?array
    {
        if (!function_exists('wp_remote_get')) return null;
        $response = wp_remote_get($url, ['timeout' => 5, 'redirection' => 2, 'limit_response_size' => 2097152]);
        if (function_exists('is_wp_error') && is_wp_error($response)) return null;
        $status = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        $body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        $mime = function_exists('wp_remote_retrieve_header') ? (string) wp_remote_retrieve_header($response, 'content-type') : '';
        return ['status' => $status, 'body' => $body, 'mime_type' => $mime];
    }

    /** @param list<array<string,mixed>|string> $candidates @return array<string,mixed> */
    public function select(array $candidates): array
    {
        return $this->selectWithPriority($candidates, self::PRIORITY);
    }

    /** @param list<array<string,mixed>|string> $candidates @return array<string,mixed> */
    public function selectCompact(array $candidates): array
    {
        return $this->selectWithPriority($candidates, self::COMPACT_PRIORITY);
    }

    /** @param list<array<string,mixed>|string> $candidates @param list<string> $priority @return array<string,mixed> */
    private function selectWithPriority(array $candidates, array $priority): array
    {
        $normalized = [];
        foreach ($candidates as $candidate) {
            $candidate = is_string($candidate) ? ['url' => $candidate] : $candidate;
            if (!is_array($candidate)) continue;
            $url = trim((string) ($candidate['url'] ?? ''));
            if ($url === '' || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') continue;
            $variant = $this->variant((string) ($candidate['variant'] ?? ''), $url);
            if (!in_array($variant, $priority, true)) continue;
            $normalized[$variant] = ['variant' => $variant, 'url' => $url];
        }
        foreach ($priority as $variant) {
            if (!isset($normalized[$variant])) continue;
            $candidate = $normalized[$variant];
            $probe = $this->probeCandidate($candidate, $candidates);
            if ($probe === null) continue;
            return [
                'url' => $candidate['url'],
                'variant' => $variant,
                'width' => $probe['width'],
                'height' => $probe['height'],
                'probed_at' => gmdate('c'),
                'probe_hash' => hash('sha256', $candidate['url'] . '|' . $probe['width'] . 'x' . $probe['height']),
            ];
        }
        return [];
    }

    /** Compact presentation only; canonical source selection remains unchanged. */
    public function presentationFromSource(array $source): array
    {
        $selection = is_array($source['thumbnail_presentation'] ?? null) ? $source['thumbnail_presentation'] : [];
        if ($selection !== []) {
            $selected = $this->fromSource(['thumbnail_selection' => $selection]);
            if ($selected !== []) return $selected;
        }
        $candidates = is_array($source['thumbnail_candidates'] ?? null) ? $source['thumbnail_candidates'] : [];
        if ($candidates !== []) {
            $selected = $this->selectCompact($candidates);
            if ($selected !== []) return $selected;
        }
        return $this->fromSource($source);
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function fromSource(array $source): array
    {
        $selection = is_array($source['thumbnail_selection'] ?? null) ? $source['thumbnail_selection'] : [];
        $url = trim((string) ($selection['url'] ?? ''));
        $width = (int) ($selection['width'] ?? 0);
        $height = (int) ($selection['height'] ?? 0);
        if ($url !== '' && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && $width > 0 && $height > 0) {
            return ['url' => $url, 'variant' => (string) ($selection['variant'] ?? $this->variant('', $url)), 'width' => $width, 'height' => $height];
        }
        $candidates = is_array($source['thumbnail_candidates'] ?? null) ? $source['thumbnail_candidates'] : [];
        if ($candidates !== [] && $this->probe !== null) return $this->select($candidates);
        // Compatibility-read for older non-YouTube source packets. Known
        // YouTube variants are intentionally not selected by filename alone;
        // they must first pass the governed probe and be persisted above.
        foreach ((array) ($source['thumbnail_urls'] ?? []) as $legacyUrl) {
            $legacyUrl = trim((string) $legacyUrl);
            if ($legacyUrl === '' || strtolower((string) parse_url($legacyUrl, PHP_URL_SCHEME)) !== 'https') continue;
            $variant = $this->variant('', $legacyUrl);
            if ($variant !== '') continue;
            return ['url' => $legacyUrl, 'variant' => $variant !== '' ? $variant : 'legacy'];
        }
        return [];
    }

    /** @param array<string,mixed> $candidate @param list<array<string,mixed>|string> $originals @return array{width:int,height:int}|null */
    private function probeCandidate(array $candidate, array $originals): ?array
    {
        $result = null;
        if ($this->probe !== null) {
            try { $result = ($this->probe)($candidate['url']); } catch (\Throwable) { return null; }
        } else {
            foreach ($originals as $original) {
                if (!is_array($original) || (string) ($original['url'] ?? '') !== $candidate['url']) continue;
                $width = (int) ($original['actual_width'] ?? 0);
                $height = (int) ($original['actual_height'] ?? 0);
                $width = $width > 0 ? $width : (int) ($original['width'] ?? 0);
                $height = $height > 0 ? $height : (int) ($original['height'] ?? 0);
                if ($width > 0 && $height > 0) $result = ['status' => 200, 'width' => $width, 'height' => $height, 'mime_type' => 'image/*'];
            }
        }
        if (!is_array($result)) return null;
        $status = (int) ($result['status'] ?? 200);
        if ($status < 200 || $status >= 300 || ($result['placeholder'] ?? false) === true) return null;
        $mime = strtolower(trim((string) ($result['mime_type'] ?? $result['content_type'] ?? '')));
        if ($mime !== '' && !str_starts_with($mime, 'image/')) return null;
        $width = (int) ($result['width'] ?? 0);
        $height = (int) ($result['height'] ?? 0);
        if (($width < 1 || $height < 1) && is_string($result['body'] ?? null) && function_exists('getimagesizefromstring')) {
            $size = @getimagesizefromstring($result['body']);
            if (is_array($size)) { $width = (int) ($size[0] ?? 0); $height = (int) ($size[1] ?? 0); }
        }
        if ($width < 1 || $height < 1) return null;
        // A tiny response is not a usable high-quality candidate. Keep the
        // lowest default only as the final bounded fallback if no better
        // source exists.
        if ($candidate['variant'] !== 'default' && ($width < 320 || $height < 180)) return null;
        return ['width' => $width, 'height' => $height];
    }

    private function variant(string $variant, string $url): string
    {
        $variant = strtolower(trim($variant));
        if ($variant === 'maxres') $variant = 'maxresdefault';
        if ($variant === 'standard') $variant = 'sddefault';
        if ($variant === 'high') $variant = 'hqdefault';
        if ($variant === 'medium') $variant = 'mqdefault';
        if ($variant !== '') return $variant;
        $name = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));
        return in_array($name, self::PRIORITY, true) ? $name : '';
    }
}
