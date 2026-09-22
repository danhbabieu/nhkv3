<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Mcp;

use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpAppsResourceRegistry, McpToolCatalog};

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
    private const WIDGET_OPEN_TOOL = 'wp_ability_nhk_v3_media_upload_widget_open';
    private const WIDGET_UPLOAD_TOOL = 'wp_ability_nhk_v3_media_widget_upload';

    /** @var list<string> */
    private const SUPPORTED_VERSIONS = ['1.7.16', '1.7.17'];

    private static bool $registered = false;
    private static bool $proxyDispatch = false;

    public static function register(): void
    {
        if (self::$registered || !function_exists('add_filter')) return;
        self::$registered = true;
        add_filter('rest_request_before_callbacks', [self::class, 'interceptMultipartCapture'], 10, 3);
        add_filter('wp_ability_normalize_input', [self::class, 'normalizeAbilityInput'], 10, 3);
        add_filter('wp_ability_validate_input', [self::class, 'validateAbilityInput'], 10, 3);
        // Easy MCP versions differ in whether their Streamable HTTP response
        // is finalized before or after WordPress serializes the REST response.
        // Project at both canonical WordPress boundaries so the connector
        // cannot receive the stale Ability schema from either path.
        add_filter('rest_post_dispatch', [self::class, 'projectToolsListDescriptor'], 10, 3);
        // WordPress applies rest_post_dispatch before it converts the response
        // object to the data that is actually JSON-encoded. Project at the
        // final echo boundary so Easy MCP/WordPress cannot serve a descriptor
        // different from the one we verified here.
        add_filter('rest_pre_echo_response', [self::class, 'projectFinalToolsListDescriptor'], 10, 3);
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
        foreach ($tools as $index => $tool) {
            if (!is_array($tool)) continue;

            // MCP tool metadata is an object when present. Easy MCP can
            // serialize an absent metadata value as an empty PHP list, which
            // becomes JSON [] and makes strict clients reject the entire
            // tools/list response (for example Pydantic: dict_type).
            if (array_key_exists('_meta', $tool) && (!is_array($tool['_meta']) || $tool['_meta'] === [] || array_is_list($tool['_meta']))) {
                unset($tool['_meta']);
            }

            $name = (string) ($tool['name'] ?? '');
            $canonical = in_array($name, [self::WIDGET_OPEN_TOOL, self::WIDGET_UPLOAD_TOOL], true)
                ? self::canonicalDefinitionForSpecialTool($name)
                : self::canonicalDefinitionForConnectorTool($name);
            if ($canonical === null) {
                if (is_array($tool['inputSchema'] ?? null)) {
                    $tool['inputSchema'] = self::normalizeFinalInputSchema($tool['inputSchema']);
                }
                $tools[$index] = $tool;
                continue;
            }

            // Keep Easy MCP's tool presence, filtering and annotations. Replace
            // only the NHK-owned descriptor fields that its serializer omitted.
            $tool['description'] = $canonical['description'];
            $tool['inputSchema'] = self::normalizeFinalInputSchema($canonical['inputSchema']);
            $isSpecialTool = in_array($name, [self::WIDGET_OPEN_TOOL, self::WIDGET_UPLOAD_TOOL], true);
            if (!$isSpecialTool) {
                $tool['_meta'] = is_array($tool['_meta'] ?? null) && !array_is_list($tool['_meta']) ? $tool['_meta'] : [];
                $tool['_meta']['nhk/schemaHash'] = McpToolCatalog::schemaHash((string) ($canonical['name'] ?? ''));
            }
            if (is_array($canonical['connectorMeta'] ?? null) && $canonical['connectorMeta'] !== [] && !array_is_list($canonical['connectorMeta'])) {
                $tool['_meta'] = array_replace_recursive(
                    is_array($tool['_meta'] ?? null) && !array_is_list($tool['_meta']) ? $tool['_meta'] : [],
                    $canonical['connectorMeta'],
                );
            }
            $tools[$index] = $tool;
        }

        return $tools;
    }

    /**
     * Normalize the final JSON Schema representation without changing its
     * semantic contract. PHP uses [] for both an empty list and an empty
     * associative map; JSON Schema requires `properties` to be an object.
     * Empty `required` is omitted because it carries no constraint and strict
     * connector validators reject the empty array form.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    public static function normalizeFinalInputSchema(array $schema): array
    {
        return self::normalizeSchemaNode($schema);
    }

    /** @param array<string,mixed> $schema @return array<string,mixed> */
    private static function normalizeSchemaNode(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object' && (!array_key_exists('properties', $schema) || $schema['properties'] === [])) {
            $schema['properties'] = new \stdClass();
        }

        if (array_key_exists('properties', $schema) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $child) {
                if (is_array($child)) $schema['properties'][$name] = self::normalizeSchemaNode($child);
            }
        }

        if (isset($schema['required']) && is_array($schema['required'])) {
            if ($schema['required'] === []) unset($schema['required']);
        }

        foreach (['items', 'additionalProperties', 'contains', 'propertyNames', 'not'] as $key) {
            if (is_array($schema[$key] ?? null)) $schema[$key] = self::normalizeSchemaNode($schema[$key]);
        }
        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $key) {
            if (!is_array($schema[$key] ?? null)) continue;
            foreach ($schema[$key] as $index => $child) {
                if (is_array($child)) $schema[$key][$index] = self::normalizeSchemaNode($child);
            }
        }

        return $schema;
    }

    public static function projectToolsListDescriptor(mixed $response, mixed $server, mixed $request): mixed
    {
        if (!is_object($request) || !method_exists($request, 'get_route') || rtrim((string) $request->get_route(), '/') !== rtrim(self::ENDPOINT, '/')) return $response;
        $rpc = self::requestRpc($request);
        if ($rpc !== null && !in_array(($rpc['method'] ?? null), ['tools/list', 'resources/list', 'resources/read'], true)) return $response;
        if (!is_object($response) || !method_exists($response, 'get_data') || !method_exists($response, 'set_data')) return $response;

        $data = $response->get_data();
        if (!is_array($data)) return $response;
        $projected = match ($rpc['method'] ?? 'tools/list') {
            'resources/list' => self::projectResourceListData($data),
            'resources/read' => self::projectResourceReadData($data, $rpc),
            default => self::projectToolsListData($data),
        };
        if ($projected !== $data) $response->set_data($projected);
        return $response;
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

    /**
     * Normalize native multipart files before WP Ability validation.
     *
     * Easy MCP executes the Ability directly with JSON arguments and does not
     * know about WP_REST_Request file params. The native PHP file bag therefore
     * has to be represented in the Ability's validation shape at this boundary;
     * the callback later removes it from JSON and reuses the native file bag.
     */
    public static function normalizeAbilityInput(mixed $input, string $abilityName, mixed $ability): mixed
    {
        // Easy MCP 1.7.17 can invoke the Ability directly after it has parsed
        // the multipart request. Do not make native-file normalization depend
        // on the compatibility proxy having been entered; the Ability must
        // see objects before WP_Ability::validate_input() on either path.
        if (!self::isSupportedInstalledVersion()) return $input;
        $files = isset($_FILES) && is_array($_FILES) ? $_FILES : [];

        return self::normalizeNativeFileInput($input, $abilityName, $files, self::installedVersion());
    }

    /**
     * Keep bare/unmaterialized connector strings and malformed file payloads
     * out of the generic JSON-schema error. They are never filesystem paths or
     * a base64 transport; only native multipart parts can reach Capture.
     */
    public static function validateAbilityInput(mixed $validity, mixed $input, string $abilityName): mixed
    {
        if ($abilityName !== 'nhk-v3/capture-ingest' || !self::isSupportedInstalledVersion() || !is_array($input)) return $validity;
        $provided = $input['files'] ?? null;
        if ($provided === null || $provided === [] || !array_key_exists('files', $input)) return $validity;

        $files = isset($_FILES) && is_array($_FILES) ? $_FILES : [];
        if (self::nativeFileDescriptors($files['files'] ?? null) !== []) return $validity;

        $error = self::nativeMultipartRequiredError();
        return $error ?? $validity;
    }

    /** @param array<string,mixed> $files @return mixed */
    public static function normalizeNativeFileInput(mixed $input, string $abilityName, array $files, string $version): mixed
    {
        if ($abilityName !== 'nhk-v3/capture-ingest' || !is_array($input) || !self::isSupportedVersion($version)) return $input;

        $nativeFiles = self::nativeFileDescriptors($files['files'] ?? null);
        if ($nativeFiles === []) return $input;

        if (array_key_exists('files', $input) && $input['files'] !== []) {
            if (!is_array($input['files']) || !array_is_list($input['files']) || count($input['files']) !== count($nativeFiles)) {
                $error = self::nativeMultipartAlignmentError();
                return $error ?? $input;
            }
        }

        $input['files'] = $nativeFiles;
        return $input;
    }

    public static function interceptMultipartCapture(mixed $response, mixed $handler, mixed $request): mixed
    {
        if (self::$proxyDispatch || !is_object($request) || !method_exists($request, 'get_route')) return $response;
        if (method_exists($request, 'get_header') && $request->get_header('X-NHK-ChatGPT-Gateway') === '1') return $response;
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

    public static function projectFinalToolsListDescriptor(mixed $data, mixed $server, mixed $request): mixed
    {
        if (!is_object($request) || !method_exists($request, 'get_route') || rtrim((string) $request->get_route(), '/') !== rtrim(self::ENDPOINT, '/')) return $data;
        $rpc = self::requestRpc($request);
        return match ($rpc['method'] ?? 'tools/list') {
            'resources/list' => self::projectResourceListData($data),
            'resources/read' => self::projectResourceReadData($data, $rpc),
            default => self::projectToolsListData($data),
        };
    }

    /** @param mixed $data @return mixed */
    private static function projectToolsListData(mixed $data): mixed
    {
        if (!is_array($data)) return $data;
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        if (!is_array($result['tools'] ?? null)) return $data;
        // Normalize the complete list, not only NHK descriptors that need
        // schema projection. Easy MCP can emit `_meta: []` for any ability;
        // one invalid entry makes strict clients reject the whole list.
        $data['result']['tools'] = self::projectTools($result['tools']);
        return $data;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function projectResourceListData(array $data): array
    {
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        if (!is_array($result['resources'] ?? null)) return $data;

        $resource = McpAppsResourceRegistry::list()['resources'][0] ?? null;
        if (!is_array($resource)) return $data;
        $hasResource = array_filter($result['resources'], static fn (mixed $candidate): bool => is_array($candidate) && ($candidate['uri'] ?? null) === $resource['uri']);
        if ($hasResource === []) $result['resources'][] = $resource;
        $data['result'] = $result;
        return $data;
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $rpc @return array<string,mixed> */
    private static function projectResourceReadData(array $data, array $rpc): array
    {
        $params = is_array($rpc['params'] ?? null) ? $rpc['params'] : [];
        $uri = (string) ($params['uri'] ?? '');
        if ($uri !== McpAppsResourceRegistry::IMAGE_UPLOAD_URI) return $data;
        $data['result'] = McpAppsResourceRegistry::read($uri);
        unset($data['error']);
        return $data;
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

    /** @return list<array<string,mixed>> */
    private static function nativeFileDescriptors(mixed $value): array
    {
        if (!is_array($value)) return [];

        if (array_key_exists('tmp_name', $value)) {
            $temporaryNames = $value['tmp_name'];
            $descriptors = [];
            if (is_array($temporaryNames)) {
                foreach ($temporaryNames as $index => $temporaryName) {
                    $error = is_array($value['error'] ?? null) ? ($value['error'][$index] ?? UPLOAD_ERR_OK) : ($value['error'] ?? UPLOAD_ERR_OK);
                    $descriptor = self::nativeFileDescriptor($value, $index, $temporaryName, $error);
                    if ($descriptor !== null) $descriptors[] = $descriptor;
                }
            } else {
                $descriptor = self::nativeFileDescriptor($value, null, $temporaryNames, $value['error'] ?? UPLOAD_ERR_OK);
                if ($descriptor !== null) $descriptors[] = $descriptor;
            }
            return $descriptors;
        }

        $descriptors = [];
        foreach ($value as $nested) {
            foreach (self::nativeFileDescriptors($nested) as $descriptor) $descriptors[] = $descriptor;
        }
        return $descriptors;
    }

    /** @param array<string,mixed> $file @return array<string,mixed>|null */
    private static function nativeFileDescriptor(array $file, int|string|null $index, mixed $temporaryName, mixed $error): ?array
    {
        if (!is_string($temporaryName)) return null;
        $value = static function (string $key) use ($file, $index): mixed {
            $candidate = $file[$key] ?? '';
            return $index !== null && is_array($candidate) ? ($candidate[$index] ?? '') : $candidate;
        };

        return [
            'name' => (string) $value('name'),
            'type' => (string) $value('type'),
            'tmp_name' => (string) $temporaryName,
            'error' => is_int($error) ? $error : (int) $error,
            'size' => (int) $value('size'),
        ];
    }

    private static function nativeMultipartRequiredError(): mixed
    {
        if (!class_exists('WP_Error')) return null;
        return new \WP_Error(
            'nhk_native_multipart_required',
            'Capture files must be supplied as native multipart file parts; opaque IDs, filesystem paths, base64 and bare descriptors are not accepted.',
            ['status' => 422, 'reason_code' => 'NATIVE_MULTIPART_FILES_REQUIRED', 'field' => 'files'],
        );
    }

    private static function nativeMultipartAlignmentError(): mixed
    {
        if (!class_exists('WP_Error')) return null;
        return new \WP_Error(
            'nhk_native_multipart_alignment',
            'Capture files must preserve one ordered native multipart part for each files[] item.',
            ['status' => 422, 'reason_code' => 'NATIVE_MULTIPART_FILES_ALIGNMENT_INVALID', 'field' => 'files'],
        );
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

    /**
     * Resolve every NHK connector descriptor from the registered catalog.
     * Easy MCP is a transport projection; it must not own a second schema
     * vocabulary or a tool-specific enum copy.
     *
     * @return array<string,mixed>|null
     */
    private static function canonicalDefinitionForConnectorTool(string $connectorTool): ?array
    {
        $toolName = McpAbilityRegistration::toolNameForConnectorTool($connectorTool);
        if ($toolName === null) {
            return null;
        }
        foreach (McpToolCatalog::tools() as $tool) if (($tool['name'] ?? '') === $toolName) return $tool;
        return null;
    }

    private static function canonicalDefinitionForSpecialTool(string $connectorTool): ?array
    {
        return match ($connectorTool) {
            self::WIDGET_OPEN_TOOL => self::widgetOpenDefinition(),
            self::WIDGET_UPLOAD_TOOL => self::widgetUploadDefinition(),
            default => null,
        };
    }

    /** @return array<string,mixed>|null */
    private static function widgetOpenDefinition(): ?array
    {
        foreach (McpToolCatalog::tools() as $tool) {
            if (($tool['name'] ?? null) !== 'nhk.media.upload-widget.open') continue;
            $uri = $tool['connectorMeta']['ui']['resourceUri'] ?? null;
            if (!is_string($uri) || $uri === '') return null;
            // Easy MCP/ChatGPT validates the final JSON Schema, where an
            // empty PHP array serializes as JSON [] rather than {} and an
            // empty required list is rejected by stricter validators.
            $tool['inputSchema'] = [
                'type' => 'object',
                'properties' => new \stdClass(),
            ];
            $tool['connectorMeta']['openai/outputTemplate'] = $uri;
            return $tool;
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private static function widgetUploadDefinition(): ?array
    {
        foreach (McpToolCatalog::tools() as $tool) {
            if (($tool['name'] ?? null) !== 'nhk.media.widget-upload') continue;

            // Easy MCP invokes registered WordPress abilities by their runtime
            // tool name. Preserve that name while restoring the complete NHK
            // contract that Easy MCP's serializer omits (notably metadata).
            return $tool;
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
