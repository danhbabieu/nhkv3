<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Mcp;

/**
 * Transport-only bridge for ChatGPT's official MCP file parameter shape.
 *
 * Easy MCP 1.7.17 authenticates the request and executes the Ability, but it
 * does not materialize OpenAI file references. This bridge runs at the Easy
 * MCP REST boundary, downloads only trusted HTTPS references to temporary
 * files, and re-enters the existing native multipart compatibility path.
 */
final class ChatGptMcpGateway
{
    public const ENDPOINT = '/easy-mcp-ai/v1/mcp';
    public const TARGET_TOOL = 'wp_ability_nhk_v3_capture_ingest';
    public const MAX_FILES = 20;
    public const MAX_TOTAL_BYTES = 52428800;
    private const TIMEOUT_SECONDS = 15;
    private const MAX_REDIRECTS = 3;

    /** @var list<string> */
    private const REFERENCE_FIELDS = ['download_url', 'file_id', 'mime_type', 'file_name'];

    /** @var list<string> */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private static bool $registered = false;
    private static bool $proxyDispatch = false;

    public static function register(): void
    {
        if (self::$registered || !function_exists('add_filter')) return;
        self::$registered = true;
        // Run before the native compatibility adapter. The nested proxy is
        // marked so the latter does not proxy the same request twice.
        add_filter('rest_request_before_callbacks', [self::class, 'intercept'], 9, 3);
    }

    public static function isProxyDispatch(): bool
    {
        return self::$proxyDispatch;
    }

    /**
     * @param array<string,mixed> $rpc
     */
    public static function shouldHandle(string $route, array $rpc): bool
    {
        if ($route !== self::ENDPOINT || ($rpc['method'] ?? null) !== 'tools/call') return false;
        $params = is_array($rpc['params'] ?? null) ? $rpc['params'] : [];
        if (($params['name'] ?? null) !== self::TARGET_TOOL) return false;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        return array_key_exists('files', $arguments) && $arguments['files'] !== [];
    }

    public static function intercept(mixed $response, mixed $handler, mixed $request): mixed
    {
        if (self::$proxyDispatch || !is_object($request) || !method_exists($request, 'get_route')) return $response;
        if (method_exists($request, 'get_header') && $request->get_header('X-NHK-ChatGPT-Gateway') === '1') return $response;

        $rpc = self::requestRpc($request);
        if ($rpc === null || !self::shouldHandle((string) $request->get_route(), $rpc)) return $response;
        $params = is_array($rpc['params'] ?? null) ? $rpc['params'] : [];
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $materialized = self::materializeReferences($arguments['files']);
            $proxyRpc = self::proxyRpcWithoutFiles($rpc);

            if (!function_exists('rest_get_server') || !class_exists('WP_REST_Request')) {
                throw new \RuntimeException('CHATGPT_FILE_GATEWAY_RUNTIME_UNAVAILABLE');
            }
            $proxy = new \WP_REST_Request('POST', (string) $request->get_route());
            if (method_exists($request, 'get_headers')) {
                foreach ((array) $request->get_headers() as $name => $value) {
                    if (strtolower((string) $name) === 'content-type') continue;
                    $proxy->set_header((string) $name, is_array($value) ? implode(', ', $value) : (string) $value);
                }
            }
            $proxy->set_header('Content-Type', 'application/json');
            $proxy->set_header('X-NHK-ChatGPT-Gateway', '1');
            $encoded = function_exists('wp_json_encode')
                ? wp_json_encode($proxyRpc)
                : json_encode($proxyRpc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) throw new \RuntimeException('CHATGPT_FILE_GATEWAY_ENCODING_FAILED');
            $proxy->set_body($encoded);
            $proxy->set_file_params($materialized['files']);

            self::$proxyDispatch = true;
            try {
                return self::withNativeFiles($materialized['files'], static fn (): mixed => rest_get_server()->dispatch($proxy));
            } finally {
                self::$proxyDispatch = false;
            }
        } catch (ChatGptMcpGatewayException $error) {
            return self::error($error->reasonCode(), $error->getMessage(), $error->host());
        } catch (\Throwable $error) {
            return self::error('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference could not be materialized.');
        } finally {
            foreach ($materialized['temporary_paths'] ?? [] as $path) {
                if (is_string($path) && is_file($path)) @unlink($path);
            }
        }
    }

    /**
     * @param mixed $provided
     * @param callable|null $downloader function(string $url, string $path, int $remainingBytes): array{status:int}
     * @param callable|null $hostPolicy function(string $host, string $url): bool
     * @return array{files:array<string,array<int|string,mixed>>,temporary_paths:list<string>}
     */
    public static function materializeReferences(mixed $provided, ?callable $downloader = null, ?callable $hostPolicy = null): array
    {
        if (!is_array($provided) || !array_is_list($provided) || $provided === [] || count($provided) > self::MAX_FILES) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_COUNT_LIMIT', 'The request must contain between one and twenty uploaded files.');
        }

        $temporaryPaths = [];
        $fileBag = ['files' => ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []]];
        $totalBytes = 0;
        try {
            foreach ($provided as $index => $reference) {
                if (!is_array($reference) || array_diff(array_keys($reference), self::REFERENCE_FIELDS) !== []) {
                    throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference is not a supported OpenAI file object.');
                }
                foreach (['download_url', 'file_id'] as $required) {
                    if (!isset($reference[$required]) || !is_string($reference[$required]) || trim($reference[$required]) === '') {
                        throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference could not be resolved.');
                    }
                }

                $url = self::validateUrl((string) $reference['download_url'], $hostPolicy);
                $path = tempnam(sys_get_temp_dir(), 'nhk-chatgpt-');
                if (!is_string($path) || $path === '') throw new ChatGptMcpGatewayException('CHATGPT_FILE_TEMP_FAILED', 'A temporary file could not be created.');
                $temporaryPaths[] = $path;
                $remaining = self::MAX_TOTAL_BYTES - $totalBytes;
                $result = $downloader !== null
                    ? $downloader($url, $path, $remaining)
                    : self::download($url, $path, $remaining, $hostPolicy);
                $status = (int) ($result['status'] ?? 0);
                if ($status < 200 || $status >= 300 || !is_file($path)) throw new ChatGptMcpGatewayException('PROVIDED_FILE_REFERENCE_UNRESOLVABLE', 'The uploaded file reference could not be downloaded.');
                $size = filesize($path);
                if (!is_int($size) || $size < 1 || $size > $remaining || $totalBytes + $size > self::MAX_TOTAL_BYTES) {
                    throw new ChatGptMcpGatewayException('CHATGPT_FILE_SIZE_LIMIT', 'The uploaded files exceed the 50 MB total limit.');
                }
                $mime = self::sniffMime($path);
                if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_REJECTED', 'The uploaded file is not a supported image.');
                if (isset($reference['mime_type']) && is_string($reference['mime_type']) && trim($reference['mime_type']) !== '' && strtolower(trim($reference['mime_type'])) !== $mime) {
                    throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_MISMATCH', 'The uploaded file MIME type does not match its bytes.');
                }
                $name = self::safeFilename((string) ($reference['file_name'] ?? ''), $url, $mime);
                $fileBag['files']['name'][] = $name;
                $fileBag['files']['type'][] = $mime;
                $fileBag['files']['tmp_name'][] = $path;
                $fileBag['files']['error'][] = UPLOAD_ERR_OK;
                $fileBag['files']['size'][] = $size;
                $totalBytes += $size;
            }
            return ['files' => $fileBag, 'temporary_paths' => $temporaryPaths];
        } catch (\Throwable $error) {
            foreach ($temporaryPaths as $path) if (is_file($path)) @unlink($path);
            throw $error;
        }
    }

    /** @param array<string,mixed> $rpc @return array<string,mixed> */
    public static function proxyRpcWithoutFiles(array $rpc): array
    {
        $params = is_array($rpc['params'] ?? null) ? $rpc['params'] : [];
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $params['arguments'] = $arguments;
        unset($params['arguments']['files']);
        $rpc['params'] = $params;
        return $rpc;
    }

    private static function validateUrl(string $url, ?callable $hostPolicy = null): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new ChatGptMcpGatewayException('CHATGPT_FILE_URL_REJECTED', 'Only trusted HTTPS file references are accepted.');
        }
        $host = rtrim(strtolower(trim((string) $parts['host'], '[]')), '.');
        $trusted = $hostPolicy !== null ? (bool) $hostPolicy($host, $url) : self::defaultHostPolicy($host, $url);
        if (!$trusted || !self::isPublicHost($host)) throw new ChatGptMcpGatewayException('CHATGPT_FILE_HOST_NOT_ALLOWED', 'CHATGPT_FILE_HOST_NOT_ALLOWED host=' . $host, $host);
        if (function_exists('wp_http_validate_url') && wp_http_validate_url($url) === false) throw new ChatGptMcpGatewayException('CHATGPT_FILE_URL_REJECTED', 'The uploaded file URL failed URL safety validation.');
        return $url;
    }

    private static function defaultHostPolicy(string $host, string $url): bool
    {
        $allowed = function_exists('apply_filters') ? apply_filters('nhk_chatgpt_file_allowed_hosts', []) : [];
        if (!is_array($allowed) || $allowed === []) return false;
        foreach ($allowed as $candidate) {
            $candidate = strtolower(ltrim(trim((string) $candidate), '.'));
            if ($candidate !== '' && ($host === $candidate || str_ends_with($host, '.' . $candidate))) return true;
        }
        return false;
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

    public static function validateRedirectTarget(string $base, string $location, ?callable $hostPolicy = null): string
    {
        $locationParts = parse_url($location);
        if (is_array($locationParts) && strtolower((string) ($locationParts['scheme'] ?? '')) === 'https' && !empty($locationParts['host'])) {
            return self::validateUrl($location, $hostPolicy);
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) throw new ChatGptMcpGatewayException('CHATGPT_FILE_REDIRECT_REJECTED', 'The uploaded file redirect is invalid.');
        if (str_starts_with($location, '//')) return self::validateUrl('https:' . $location, $hostPolicy);
        if (str_starts_with($location, '/')) return self::validateUrl('https://' . $baseParts['host'] . $location, $hostPolicy);
        throw new ChatGptMcpGatewayException('CHATGPT_FILE_REDIRECT_REJECTED', 'The uploaded file redirect is invalid.');
    }

    private static function sniffMime(string $path): string
    {
        if (!class_exists('finfo')) throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_UNAVAILABLE', 'The file MIME sniffer is unavailable.');
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if (!is_string($mime) || $mime === '') throw new ChatGptMcpGatewayException('CHATGPT_FILE_MIME_REJECTED', 'The uploaded file MIME type could not be verified.');
        return strtolower($mime);
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

    private static function error(string $reasonCode, string $message, ?string $host = null): mixed
    {
        $data = ['status' => 422, 'reason_code' => $reasonCode, 'field' => 'files'];
        if ($host !== null && $host !== '') $data['host'] = $host;
        if (class_exists('WP_Error')) return new \WP_Error('nhk_chatgpt_file_gateway', $message, $data);
        return $message;
    }

    private static function withNativeFiles(array $files, callable $callback): mixed
    {
        $hadFiles = array_key_exists('_FILES', $GLOBALS);
        $previousFiles = $GLOBALS['_FILES'] ?? null;
        $_FILES = $files;
        try { return $callback(); } finally {
            if ($hadFiles) $_FILES = $previousFiles;
            else unset($GLOBALS['_FILES']);
        }
    }

    /** @return array<string,mixed>|null */
    private static function requestRpc(object $request): ?array
    {
        foreach (['request', 'json', 'payload'] as $field) {
            if (!method_exists($request, 'get_param')) continue;
            $candidate = $request->get_param($field);
            if (!is_string($candidate) || trim($candidate) === '') continue;
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) return $decoded;
        }
        if (method_exists($request, 'get_json_params')) {
            $decoded = $request->get_json_params();
            if (is_array($decoded)) return $decoded;
        }
        return null;
    }
}

final class ChatGptMcpGatewayException extends \RuntimeException
{
    public function __construct(private string $reasonCode, string $message, private ?string $host = null)
    {
        parent::__construct($message);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function host(): ?string
    {
        return $this->host;
    }
}
