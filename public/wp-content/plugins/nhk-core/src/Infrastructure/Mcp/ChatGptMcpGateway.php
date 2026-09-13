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
        return TrustedProvidedFileMaterializer::materialize($provided, $downloader, $hostPolicy);
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

    public static function validateRedirectTarget(string $base, string $location, ?callable $hostPolicy = null): string
    {
        return TrustedProvidedFileMaterializer::validateRedirectTarget($base, $location, $hostPolicy);
    }

    /** @param list<mixed> $allowed */
    private static function isExactAllowlistedHost(string $host, array $allowed): bool
    {
        return TrustedProvidedFileMaterializer::isExactAllowlistedHost($host, $allowed);
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
