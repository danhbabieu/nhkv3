<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Mcp;

use NHK\Core\Application\Mcp\McpToolCatalog;

/**
 * Narrow compatibility boundary for Easy MCP versions that do not forward
 * OpenAI file parameters or accept multipart MCP calls.
 *
 * Easy MCP remains the owner of authentication, token scope and permission
 * checks. The adapter only re-enters the same Easy MCP route with a JSON proxy
 * after preserving the original PHP multipart parts in $_FILES; the governed
 * NHK Ability then delegates to the canonical NHK MCP transport.
 */
final class EasyMcpNativeFileCompatibilityAdapter
{
    private const ENDPOINT = '/easy-mcp-ai/v1/mcp';
    private const TARGET_TOOL = 'wp_ability_nhk_v3_capture_ingest';

    /** @var list<string> */
    private const SUPPORTED_VERSIONS = ['1.7.16', '1.7.17'];

    private static bool $registered = false;
    private static bool $proxyDispatch = false;

    public static function register(): void
    {
        if (self::$registered || !function_exists('add_filter')) return;
        self::$registered = true;
        add_filter('rest_request_before_callbacks', [self::class, 'interceptMultipartCapture'], 10, 3);
        add_filter('rest_post_dispatch', [self::class, 'projectToolsListDescriptor'], 10, 3);
    }

    public static function isSupportedVersion(string $version): bool
    {
        return in_array($version, self::SUPPORTED_VERSIONS, true);
    }

    /** @return array{code:string,version:string,native_file_support_upstream:bool} */
    public static function diagnosticForVersion(string $version): array
    {
        return [
            'code' => self::isSupportedVersion($version) ? 'EASY_MCP_NATIVE_FILE_COMPAT_ACTIVE' : 'EASY_MCP_VERSION_UNSUPPORTED',
            'version' => $version,
            'native_file_support_upstream' => false,
        ];
    }

    /** @param list<array<string,mixed>> $tools @return list<array<string,mixed>> */
    public static function projectTools(array $tools): array
    {
        $canonical = self::captureDefinition();
        if ($canonical === null) return $tools;

        foreach ($tools as $index => $tool) {
            if (!is_array($tool) || (string) ($tool['name'] ?? '') !== self::TARGET_TOOL) continue;

            // Keep Easy MCP's tool presence, filtering and annotations. Replace
            // only the NHK-owned descriptor fields that its serializer omitted.
            $tool['description'] = $canonical['description'];
            $tool['inputSchema'] = $canonical['inputSchema'];
            $tool['_meta'] = $canonical['connectorMeta'] ?? [];
            $tools[$index] = $tool;
        }

        return $tools;
    }

    /** @param array<string,mixed> $rpc @param array<string,mixed> $files */
    public static function shouldHandle(string $route, array $rpc, array $files, string $version): bool
    {
        if ($route !== self::ENDPOINT || !self::isSupportedVersion($version)) return false;
        if (($rpc['method'] ?? null) !== 'tools/call') return false;
        $params = is_array($rpc['params'] ?? null) ? $rpc['params'] : [];
        if (($params['name'] ?? null) !== self::TARGET_TOOL) return false;

        $batch = $files['files'] ?? null;
        return is_array($batch) && self::containsNativeFile($batch);
    }

    public static function interceptMultipartCapture(mixed $response, mixed $handler, mixed $request): mixed
    {
        if (self::$proxyDispatch || !is_object($request) || !method_exists($request, 'get_route')) return $response;
        if (!self::isSupportedInstalledVersion() || !method_exists($request, 'get_file_params')) return $response;

        $files = $request->get_file_params();
        $rpc = self::requestRpc($request);
        if (!is_array($files) || $rpc === null || !self::shouldHandle((string) $request->get_route(), $rpc, $files, self::installedVersion())) return $response;
        if (!function_exists('rest_get_server') || !class_exists('WP_REST_Request')) return $response;

        $proxy = new \WP_REST_Request('POST', (string) $request->get_route());
        if (method_exists($request, 'get_headers')) {
            foreach ((array) $request->get_headers() as $name => $value) {
                if (strtolower((string) $name) === 'content-type') continue;
                $proxy->set_header((string) $name, is_array($value) ? implode(', ', $value) : (string) $value);
            }
        }
        $proxy->set_header('Content-Type', 'application/json');
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($rpc) : json_encode($rpc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) return $response;
        $proxy->set_body($encoded);

        self::$proxyDispatch = true;
        try {
            // Easy MCP performs its normal authentication, scope and
            // capability checks first. Keep the request's native PHP parts
            // available to the Ability callback during that nested dispatch;
            // some REST callers populate WP_REST_Request::FILES without
            // populating the PHP superglobal.
            return self::withNativeFiles($files, static fn (): mixed => rest_get_server()->dispatch($proxy));
        } finally {
            self::$proxyDispatch = false;
        }
    }

    public static function projectToolsListDescriptor(mixed $response, mixed $server, mixed $request): mixed
    {
        if (!self::isSupportedInstalledVersion() || !is_object($request) || !method_exists($request, 'get_route') || (string) $request->get_route() !== self::ENDPOINT) return $response;
        $rpc = self::requestRpc($request);
        if (($rpc['method'] ?? null) !== 'tools/list' || !is_object($response) || !method_exists($response, 'get_data') || !method_exists($response, 'set_data')) return $response;

        $data = $response->get_data();
        if (!is_array($data)) return $response;
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        if (!is_array($result['tools'] ?? null)) return $response;
        $data['result']['tools'] = self::projectTools($result['tools']);
        $response->set_data($data);
        return $response;
    }

    private static function installedVersion(): string
    {
        return defined('EASY_MCP_AI_VERSION') ? (string) constant('EASY_MCP_AI_VERSION') : '';
    }

    private static function isSupportedInstalledVersion(): bool
    {
        return self::isSupportedVersion(self::installedVersion());
    }

    private static function containsNativeFile(mixed $value): bool
    {
        if (!is_array($value)) return false;
        if (array_key_exists('tmp_name', $value)) {
            $temporaryNames = $value['tmp_name'];
            $errors = $value['error'] ?? null;
            if (is_string($temporaryNames)) return self::isSuccessfulUpload($temporaryNames, $errors);
            if (!is_array($temporaryNames)) return false;
            foreach ($temporaryNames as $index => $temporaryName) {
                $error = is_array($errors) ? ($errors[$index] ?? null) : $errors;
                if (self::isSuccessfulUpload($temporaryName, $error)) return true;
            }
            return false;
        }
        foreach ($value as $nested) {
            if (is_array($nested) && self::containsNativeFile($nested)) return true;
        }
        return false;
    }

    private static function isSuccessfulUpload(mixed $temporaryName, mixed $error): bool
    {
        if (!is_string($temporaryName) || $temporaryName === '') return false;
        return $error === null || $error === UPLOAD_ERR_OK;
    }

    /** @param array<string,mixed> $files */
    private static function withNativeFiles(array $files, callable $callback): mixed
    {
        $hadFiles = array_key_exists('_FILES', $GLOBALS);
        $previousFiles = $GLOBALS['_FILES'] ?? null;
        $_FILES = $files;

        try {
            return $callback();
        } finally {
            if ($hadFiles) {
                $_FILES = $previousFiles;
            } else {
                unset($GLOBALS['_FILES']);
            }
        }
    }

    /** @return array<string,mixed>|null */
    private static function captureDefinition(): ?array
    {
        foreach (McpToolCatalog::tools() as $tool) {
            if (($tool['name'] ?? null) === 'nhk.capture.ingest') return $tool;
        }
        return null;
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
