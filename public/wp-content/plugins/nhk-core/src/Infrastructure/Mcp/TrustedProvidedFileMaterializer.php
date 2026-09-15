<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Mcp;

/**
 * Materializes only the trusted file references accepted at the MCP
 * transport boundary into a native PHP upload bag.
 */
final class TrustedProvidedFileMaterializer
{
    public const MAX_FILES = 20;
    public const MAX_TOTAL_BYTES = 52428800;
    public const MAX_DECODED_PIXELS = 40000000;
    public const MAX_DIMENSION = 10000;
    private const TIMEOUT_SECONDS = 15;
    private const MAX_REDIRECTS = 3;
    private const REFERENCE_FIELDS = ['download_url', 'file_id', 'mime_type', 'file_name'];
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * @param callable|null $downloader function(string $url, string $path, int $remainingBytes): array{status:int}
     * @param callable|null $hostPolicy function(string $host, string $url): bool
     * @return array{files:array<string,array<int|string,mixed>>,temporary_paths:list<string>}
     */
    public static function materialize(mixed $provided, ?callable $downloader = null, ?callable $hostPolicy = null): array
    {
        if (!is_array($provided) || !array_is_list($provided) || $provided === [] || count($provided) > self::MAX_FILES) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_COUNT_LIMIT', 'The request must contain between one and twenty uploaded files.');
        }

        $temporaryPaths = [];
        $fileBag = ['files' => ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []]];
        $totalBytes = 0;
        try {
            foreach ($provided as $reference) {
                $declaredMime = '';
                $providedName = '';
                if (is_string($reference)) {
                    $reference = trim($reference);
                    if (!str_starts_with(strtolower($reference), 'https://')) {
                        throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference could not be resolved.');
                    }
                    $url = self::validateUrl($reference, $hostPolicy);
                } else {
                    if (!is_array($reference) || array_diff(array_keys($reference), self::REFERENCE_FIELDS) !== []) {
                        throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference is not a supported OpenAI file object.');
                    }
                    foreach (['download_url', 'file_id'] as $required) {
                        if (!isset($reference[$required]) || !is_string($reference[$required]) || trim($reference[$required]) === '') {
                            throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference could not be resolved.');
                        }
                    }
                    $declaredMime = trim((string) ($reference['mime_type'] ?? ''));
                    $providedName = trim((string) ($reference['file_name'] ?? ''));
                    $url = self::validateUrl((string) $reference['download_url'], $hostPolicy);
                }

                $path = tempnam(sys_get_temp_dir(), 'nhk-chatgpt-');
                if (!is_string($path) || $path === '') throw new ChatGptMcpGatewayException('CHATGPT_FILE_TEMP_FAILED', 'A temporary file could not be created.');
                $temporaryPaths[] = $path;
                $remaining = self::MAX_TOTAL_BYTES - $totalBytes;
                $result = $downloader !== null ? $downloader($url, $path, $remaining) : self::download($url, $path, $remaining, $hostPolicy);
                $status = (int) ($result['status'] ?? 0);
                if ($status < 200 || $status >= 300 || !is_file($path)) throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference could not be downloaded.');
                $size = filesize($path);
                if (!is_int($size) || $size < 1 || $size > $remaining || $totalBytes + $size > self::MAX_TOTAL_BYTES) throw new ChatGptMcpGatewayException('CHATGPT_FILE_SIZE_LIMIT', 'The uploaded files exceed the 50 MB total limit.');
                $mime = self::sniffMime($path);
                if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_REJECTED', 'The uploaded file is not a supported image.');
                if ($declaredMime !== '' && strtolower($declaredMime) !== $mime) throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_MISMATCH', 'The uploaded file MIME type does not match its bytes.');
                self::assertImageResourceBudget($path, $mime);
                $fileBag['files']['name'][] = self::safeFilename($providedName, $url, $mime);
                $fileBag['files']['type'][] = $mime;
                $fileBag['files']['tmp_name'][] = $path;
                $fileBag['files']['error'][] = UPLOAD_ERR_OK;
                $fileBag['files']['size'][] = $size;
                $totalBytes += $size;
            }
            return ['files' => $fileBag, 'temporary_paths' => $temporaryPaths];
        } catch (\Throwable $error) {
            self::cleanup($temporaryPaths);
            throw $error;
        }
    }

    public static function validateRedirectTarget(string $base, string $location, ?callable $hostPolicy = null): string
    {
        $locationParts = parse_url($location);
        if (is_array($locationParts) && strtolower((string) ($locationParts['scheme'] ?? '')) === 'https' && !empty($locationParts['host'])) return self::validateUrl($location, $hostPolicy);
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) throw new ChatGptMcpGatewayException('CHATGPT_FILE_REDIRECT_REJECTED', 'The uploaded file redirect is invalid.');
        if (str_starts_with($location, '//')) return self::validateUrl('https:' . $location, $hostPolicy);
        if (str_starts_with($location, '/')) return self::validateUrl('https://' . $baseParts['host'] . $location, $hostPolicy);
        throw new ChatGptMcpGatewayException('CHATGPT_FILE_REDIRECT_REJECTED', 'The uploaded file redirect is invalid.');
    }

    public static function isExactAllowlistedHost(string $host, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            $candidate = strtolower(ltrim(trim((string) $candidate), '.'));
            if ($candidate !== '' && $host === $candidate) return true;
        }
        return false;
    }

    private static function validateUrl(string $url, ?callable $hostPolicy = null): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && (int) $parts['port'] !== 443) throw new ChatGptMcpGatewayException('CHATGPT_FILE_URL_REJECTED', 'Only trusted HTTPS file references are accepted.');
        $host = rtrim(strtolower(trim((string) $parts['host'], '[]')), '.');
        $trusted = $hostPolicy !== null ? (bool) $hostPolicy($host, $url) : self::defaultHostPolicy($host);
        if (!$trusted || !self::isPublicHost($host)) throw new ChatGptMcpGatewayException('CHATGPT_FILE_HOST_NOT_ALLOWED', 'CHATGPT_FILE_HOST_NOT_ALLOWED host=' . $host, $host);
        if (function_exists('wp_http_validate_url') && wp_http_validate_url($url) === false) throw new ChatGptMcpGatewayException('CHATGPT_FILE_URL_REJECTED', 'The uploaded file URL failed URL safety validation.');
        return $url;
    }

    private static function defaultHostPolicy(string $host): bool
    {
        $allowed = function_exists('apply_filters') ? apply_filters('nhk_chatgpt_file_allowed_hosts', []) : [];
        return is_array($allowed) && $allowed !== [] && self::isExactAllowlistedHost($host, $allowed);
    }

    private static function isPublicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) return self::isPublicIp($host);
        if (!function_exists('dns_get_record')) return true;
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) return true;
        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip !== '' && !self::isPublicIp($ip)) return false;
        }
        return true;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function download(string $url, string $path, int $remaining, ?callable $hostPolicy): array
    {
        if (!function_exists('wp_safe_remote_get')) throw new ChatGptMcpGatewayException('CHATGPT_FILE_GATEWAY_RUNTIME_UNAVAILABLE', 'The trusted file downloader is unavailable.');
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            self::validateUrl($current, $hostPolicy);
            if (is_file($path)) @unlink($path);
            $response = wp_safe_remote_get($current, ['timeout' => self::TIMEOUT_SECONDS, 'redirection' => 0, 'stream' => true, 'filename' => $path, 'limit_response_size' => $remaining, 'reject_unsafe_urls' => true]);
            if (is_wp_error($response)) throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference could not be downloaded.');
            $status = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = function_exists('wp_remote_retrieve_header') ? (string) wp_remote_retrieve_header($response, 'location') : '';
                if ($location === '') throw new ChatGptMcpGatewayException('CHATGPT_FILE_REDIRECT_REJECTED', 'The uploaded file redirect is invalid.');
                $current = self::validateRedirectTarget($current, $location, $hostPolicy);
                continue;
            }
            return ['status' => $status];
        }
        throw new ChatGptMcpGatewayException('CHATGPT_FILE_REDIRECT_REJECTED', 'The uploaded file redirect chain is too long.');
    }

    private static function sniffMime(string $path): string
    {
        if (!class_exists('finfo')) throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_UNAVAILABLE', 'The file MIME sniffer is unavailable.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || $mime === '') throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_REJECTED', 'The uploaded file MIME type could not be verified.');
        return strtolower($mime);
    }

    private static function assertImageResourceBudget(string $path, string $mime): void
    {
        if ($mime === 'image/svg+xml') throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_REJECTED', 'Active image formats are not accepted.');
        $info = @getimagesize($path);
        if (!is_array($info)) throw new ChatGptMcpGatewayException('CHATGPT_FILE_DECODE_FAILED', 'The uploaded image could not be decoded.');
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_DECODED_DIMENSION_LIMIT', 'The uploaded image dimensions exceed the safe decoder budget.');
        }
        if ($width > intdiv(self::MAX_DECODED_PIXELS, max(1, $height))) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_DECODED_PIXEL_LIMIT', 'The uploaded image exceeds the safe decoded-pixel budget.');
        }
    }

    private static function safeFilename(string $provided, string $url, string $mime): string
    {
        $name = trim($provided);
        if ($name === '') {
            $path = parse_url($url, PHP_URL_PATH);
            $name = is_string($path) ? basename($path) : '';
        }
        if (function_exists('sanitize_file_name')) $name = sanitize_file_name($name);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: '';
        $name = trim($name, '.-');
        if ($name === '') $name = 'chatgpt-file';
        if (!str_contains($name, '.')) $name .= match ($mime) { 'image/png' => '.png', 'image/gif' => '.gif', 'image/webp' => '.webp', default => '.jpg' };
        return substr($name, 0, 180);
    }

    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) if (is_string($path) && is_file($path)) @unlink($path);
    }
}
