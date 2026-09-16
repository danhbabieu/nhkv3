<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Mcp;

/**
 * Materializes structured provided-file references accepted at the MCP
 * boundary into a native PHP upload bag.
 *
 * The provided-file shape is trusted only because it arrived through the
 * capability-gated widget transport. The download URL is still treated as an
 * untrusted network destination and is independently validated and pinned.
 */
final class TrustedProvidedFileMaterializer
{
    public const MAX_FILES = 20;
    public const MAX_TOTAL_BYTES = 52428800;
    public const MAX_DECODED_PIXELS = 40000000;
    public const MAX_DIMENSION = 10000;
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TIMEOUT_SECONDS = 15;
    private const MAX_REDIRECTS = 2;
    private const MAX_FILE_ID_LENGTH = 512;
    private const MAX_OPTIONAL_FIELD_LENGTH = 512;
    private const REFERENCE_FIELDS = ['download_url', 'file_id', 'mime_type', 'file_name'];
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * @param callable|null $downloader function(string $url, string $path, int $remainingBytes): array{status:int}
     * @param callable|null $hostPolicy legacy host diagnostic observer (string $host); never an authorization gate and never receives the URL
     * @param callable|null $resolver function(string $host): list<string>|list<array<string,mixed>>
     * @return array{files:array<string,array<int|string,mixed>>,temporary_paths:list<string>}
     */
    public static function materialize(mixed $provided, ?callable $downloader = null, ?callable $hostPolicy = null, ?callable $resolver = null): array
    {
        if (!is_array($provided) || !array_is_list($provided) || $provided === [] || count($provided) > self::MAX_FILES) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_COUNT_LIMIT', 'The request must contain between one and twenty uploaded files.');
        }

        $temporaryPaths = [];
        $fileBag = ['files' => ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []]];
        $totalBytes = 0;
        try {
            foreach ($provided as $reference) {
                if (!is_array($reference) || array_diff(array_keys($reference), self::REFERENCE_FIELDS) !== []) {
                    throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference is not a supported OpenAI file object.');
                }
                foreach (['download_url', 'file_id'] as $required) {
                    if (!is_string($reference[$required] ?? null) || self::boundedTrim((string) $reference[$required], self::MAX_FILE_ID_LENGTH) === '') {
                        throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference could not be resolved.');
                    }
                }
                foreach (['mime_type', 'file_name'] as $optional) {
                    if (array_key_exists($optional, $reference) && (!is_string($reference[$optional]) || strlen((string) $reference[$optional]) > self::MAX_OPTIONAL_FIELD_LENGTH)) {
                        throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference could not be resolved.');
                    }
                }

                $declaredMime = trim((string) ($reference['mime_type'] ?? ''));
                $providedName = trim((string) ($reference['file_name'] ?? ''));
                $url = self::validateUrl((string) $reference['download_url'], $hostPolicy, $resolver);
                $path = self::temporaryPath();
                $temporaryPaths[] = $path;
                $remaining = self::MAX_TOTAL_BYTES - $totalBytes;
                $urlParts = parse_url($url);
                if (!is_array($urlParts) || !isset($urlParts['host'])) throw new ChatGptMcpGatewayException('CHATGPT_FILE_URL_REJECTED', 'The uploaded file URL is invalid.');
                self::resolvePublicAddresses(self::normalizeHost((string) $urlParts['host']), $resolver);
                $result = $downloader !== null
                    ? $downloader($url, $path, $remaining)
                    : self::download($url, $path, $remaining, $hostPolicy, $resolver);
                $status = (int) ($result['status'] ?? 0);
                if ($status < 200 || $status >= 300 || !is_file($path)) {
                    throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference could not be downloaded.');
                }

                $size = filesize($path);
                if (!is_int($size) || $size < 1 || $size > $remaining || $totalBytes + $size > self::MAX_TOTAL_BYTES) {
                    throw new ChatGptMcpGatewayException('CHATGPT_FILE_SIZE_LIMIT', 'The uploaded files exceed the 50 MB total limit.');
                }
                $mime = self::sniffMime($path);
                if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                    throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_REJECTED', 'The uploaded file is not a supported image.');
                }
                if ($declaredMime !== '' && strtolower($declaredMime) !== $mime) {
                    throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_MISMATCH', 'The uploaded file MIME type does not match its bytes.');
                }
                self::assertImageResourceBudget($path);
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

    public static function validateRedirectTarget(string $base, string $location, ?callable $hostPolicy = null, ?callable $resolver = null): string
    {
        $location = trim($location);
        if ($location === '' || str_contains($location, "\r") || str_contains($location, "\n")) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_REDIRECT_REJECTED', 'The uploaded file redirect is invalid.');
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || strtolower((string) ($baseParts['scheme'] ?? '')) !== 'https' || empty($baseParts['host'])) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_REDIRECT_REJECTED', 'The uploaded file redirect is invalid.');
        }
        if (parse_url($location, PHP_URL_SCHEME) !== null || str_starts_with($location, '//')) {
            return self::validateUrl(str_starts_with($location, '//') ? 'https:' . $location : $location, $hostPolicy, $resolver);
        }
        if (str_starts_with($location, '/')) {
            return self::validateUrl('https://' . $baseParts['host'] . $location, $hostPolicy, $resolver);
        }
        throw new ChatGptMcpGatewayException('CHATGPT_FILE_REDIRECT_REJECTED', 'The uploaded file redirect is invalid.');
    }

    private static function validateUrl(string $url, ?callable $hostPolicy = null, ?callable $resolver = null): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_URL_REJECTED', 'Only HTTPS file references on port 443 are accepted.');
        }
        $host = self::normalizeHost((string) $parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_URL_REJECTED', 'IP-literal file destinations are not accepted.');
        }
        self::observeHost($host, $hostPolicy);
        self::resolvePublicAddresses($host, $resolver);
        return $url;
    }

    private static function normalizeHost(string $host): string
    {
        $host = rtrim(strtolower(trim($host, "[] \t\r\n")), '.');
        if ($host === '' || strlen($host) > 253 || preg_match('/[^a-z0-9.-]/', $host) === 1 || str_starts_with($host, '.') || str_ends_with($host, '.') || str_contains($host, '..')) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_URL_REJECTED', 'The uploaded file hostname is invalid.');
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || $label[0] === '-' || str_ends_with($label, '-') || preg_match('/^[a-z0-9-]+$/', $label) !== 1) {
                throw new ChatGptMcpGatewayException('CHATGPT_FILE_URL_REJECTED', 'The uploaded file hostname is invalid.');
            }
        }
        return $host;
    }

    private static function observeHost(string $host, ?callable $observer): void
    {
        if ($observer === null) return;
        try { $observer($host); } catch (\Throwable) { /* diagnostics never change authorization */ }
    }

    /** @return list<string> */
    private static function resolvePublicAddresses(string $host, ?callable $resolver): array
    {
        $records = $resolver !== null ? $resolver($host) : (function_exists('dns_get_record') ? @dns_get_record($host, DNS_A | DNS_AAAA) : false);
        if (!is_array($records) || $records === []) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_DNS_FAILED', 'The uploaded file hostname could not be resolved.', $host);
        }
        $addresses = [];
        foreach ($records as $record) {
            $ip = is_string($record) ? trim($record) : (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip === '' || !self::isPublicIp($ip)) {
                throw new ChatGptMcpGatewayException('CHATGPT_FILE_PRIVATE_IP_REJECTED', 'The uploaded file hostname resolved to a non-public destination.', $host);
            }
            $addresses[] = $ip;
        }
        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) throw new ChatGptMcpGatewayException('CHATGPT_FILE_DNS_FAILED', 'The uploaded file hostname could not be resolved.', $host);
        return $addresses;
    }

    private static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
        $packed = @inet_pton($ip);
        if (!is_string($packed)) return false;
        if (strlen($packed) === 4) {
            $unpacked = unpack('N', $packed);
            $value = (int) ($unpacked[1] ?? 0);
            $value = $value < 0 ? $value + 4294967296 : $value;
            foreach ([['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$network, $bits]) {
                $startRaw = ip2long($network);
                $start = $startRaw < 0 ? $startRaw + 4294967296 : $startRaw;
                $size = 2 ** (32 - $bits);
                if ($value >= $start && $value < $start + $size) return false;
            }
            return true;
        }
        if (strlen($packed) !== 16) return false;
        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") return false;
        if ($packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15) . "\1") return false;
        if ((ord($packed[0]) & 0xfe) === 0xfc || (ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80) || ord($packed[0]) === 0xff) return false;
        if (substr($packed, 0, 4) === " \1\r\xb8") return false;
        return true;
    }

    private static function download(string $url, string $path, int $remaining, ?callable $hostPolicy, ?callable $resolver): array
    {
        if (!function_exists('curl_init')) throw new ChatGptMcpGatewayException('CHATGPT_FILE_GATEWAY_RUNTIME_UNAVAILABLE', 'The trusted file downloader is unavailable.');
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = parse_url($current);
            $host = is_array($parts) ? self::normalizeHost((string) ($parts['host'] ?? '')) : '';
            $addresses = self::resolvePublicAddresses($host, $resolver);
            self::observeHost($host, $hostPolicy);
            if (is_file($path)) @unlink($path);
            $handle = @fopen($path, 'wb');
            if (!is_resource($handle)) throw new ChatGptMcpGatewayException('CHATGPT_FILE_TEMP_FAILED', 'A temporary file could not be opened.');
            $written = 0;
            $overflow = false;
            $writeFailed = false;
            $location = '';
            $curl = curl_init();
            if ($curl === false) { fclose($handle); throw new ChatGptMcpGatewayException('CHATGPT_FILE_GATEWAY_RUNTIME_UNAVAILABLE', 'The trusted file downloader is unavailable.'); }
            curl_setopt_array($curl, [
                CURLOPT_URL => $current,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADER => false,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => self::TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: image/*'],
                CURLOPT_USERAGENT => 'NHK-V3-media-materializer/1.0',
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_RESOLVE => array_map(static fn (string $ip): string => $host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip), $addresses),
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                    if (stripos($header, 'Location:') === 0) $location = trim(substr($header, strlen('Location:')));
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($handle, $remaining, &$written, &$overflow, &$writeFailed): int {
                    $length = strlen($chunk);
                    if ($written + $length > $remaining) { $overflow = true; return 0; }
                    $count = fwrite($handle, $chunk);
                    if ($count !== $length) { $writeFailed = true; return 0; }
                    $written += $count;
                    return $count;
                },
            ]);
            $ok = curl_exec($curl);
            $errno = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            fclose($handle);
            if ($overflow) throw new ChatGptMcpGatewayException('CHATGPT_FILE_SIZE_LIMIT', 'The uploaded files exceed the 50 MB total limit.');
            if ($writeFailed || $ok === false && $errno !== 0) throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference could not be downloaded.');
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($location === '' || $hop >= self::MAX_REDIRECTS) throw new ChatGptMcpGatewayException('CHATGPT_FILE_REDIRECT_REJECTED', 'The uploaded file redirect chain is too long or invalid.');
                $current = self::validateRedirectTarget($current, $location, $hostPolicy, $resolver);
                continue;
            }
            return ['status' => $status];
        }
        throw new ChatGptMcpGatewayException('CHATGPT_FILE_REDIRECT_REJECTED', 'The uploaded file redirect chain is too long.');
    }

    private static function temporaryPath(): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nhk-v3-media-materializer';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new ChatGptMcpGatewayException('CHATGPT_FILE_TEMP_FAILED', 'A temporary file could not be created.');
        $directoryReal = realpath($directory);
        $webrootReal = defined('ABSPATH') ? realpath((string) ABSPATH) : false;
        if ($directoryReal === false || (is_string($webrootReal) && self::within($webrootReal, $directoryReal))) throw new ChatGptMcpGatewayException('CHATGPT_FILE_TEMP_FAILED', 'The temporary file directory is not private.');
        $path = tempnam($directory, 'nhk-');
        if (!is_string($path) || $path === '') throw new ChatGptMcpGatewayException('CHATGPT_FILE_TEMP_FAILED', 'A temporary file could not be created.');
        @chmod($path, 0600);
        return $path;
    }

    private static function sniffMime(string $path): string
    {
        if (!class_exists('finfo')) throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_UNAVAILABLE', 'The file MIME sniffer is unavailable.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || $mime === '') throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_REJECTED', 'The uploaded file MIME type could not be verified.');
        return strtolower($mime);
    }

    private static function assertImageResourceBudget(string $path): void
    {
        $info = @getimagesize($path);
        if (!is_array($info)) throw new ChatGptMcpGatewayException('CHATGPT_FILE_DECODE_FAILED', 'The uploaded image could not be decoded.');
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $width > intdiv(self::MAX_DECODED_PIXELS, max(1, $height))) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_DECODED_PIXEL_LIMIT', 'The uploaded image exceeds the safe decoded-pixel budget.');
        }
        if (function_exists('imagecreatefromstring')) {
            $bytes = @file_get_contents($path);
            $image = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
            if ($image === false) throw new ChatGptMcpGatewayException('CHATGPT_FILE_DECODE_FAILED', 'The uploaded image could not be decoded.');
            if (function_exists('imagedestroy')) imagedestroy($image);
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

    private static function boundedTrim(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) return '';
        return $value;
    }

    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) if (is_string($path) && is_file($path)) @unlink($path);
    }

    private static function within(string $root, string $path): bool
    {
        return $root !== '' && $root !== '.' && str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
