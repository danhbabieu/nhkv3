<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Mcp\EasyMcpNativeFileCompatibilityAdapter;
use NHK\Core\Application\Mcp\McpToolCatalog;
use NHK\Core\Application\Mcp\McpAbilityRegistration;
use PHPUnit\Framework\TestCase;

final class EasyMcpNativeFileCompatibilityAdapterTest extends TestCase
{
    private const TARGET = 'wp_ability_nhk_v3_capture_ingest';

    public function test_stripped_capture_descriptor_is_projected_from_canonical_catalog(): void
    {
        $tools = [[
            'name' => self::TARGET,
            'description' => 'stale Easy MCP description',
            'inputSchema' => ['type' => 'object', 'properties' => []],
            'annotations' => ['title' => 'Capture'],
        ]];

        $projected = EasyMcpNativeFileCompatibilityAdapter::projectTools($tools);
        $capture = $projected[0];

        self::assertArrayHasKey('capture_id', $capture['inputSchema']['properties']);
        self::assertSame(['files'], $capture['_meta']['openai/fileParams']);
        self::assertSame('array', $capture['inputSchema']['properties']['files']['type']);
        self::assertSame('binary', $capture['inputSchema']['properties']['files']['items']['format']);
    }

    public function test_projection_does_not_change_unrelated_tools(): void
    {
        $tool = ['name' => 'wp_ability_nhk_v3_media_ingest', 'inputSchema' => ['type' => 'object']];

        self::assertSame([$tool], EasyMcpNativeFileCompatibilityAdapter::projectTools([$tool]));
    }

    public function test_final_easy_mcp_1716_pipeline_projects_canonical_capture_descriptor(): void
    {
        $catalog = array_column(McpToolCatalog::tools(), null, 'name');
        $capture = $catalog['nhk.capture.ingest'];

        // This simulates Easy MCP 1.7.16's real path:
        // wp_get_abilities() -> Dynamic_Tool_Registrar -> Tool_Registry ->
        // Server::handle_tools_list() -> Transport -> final REST data.
        // The upstream registrar copies Ability::get_input_schema() into the
        // Dynamic_Tool definition, while Base_Tool::get_definition() does not
        // carry NHK connector metadata. The final REST data is therefore the
        // exact boundary this adapter must repair.
        $abilities = [
            'nhk-v3/capture-ingest' => [
                'label' => 'Capture',
                'description' => $capture['description'],
                'input_schema' => $capture['inputSchema'],
                'annotations' => [],
            ],
            'nhk-v3/media-ingest' => [
                'label' => 'Media',
                'description' => 'unrelated tool',
                'input_schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
                'annotations' => [],
            ],
        ];
        $response = self::simulateEasyMcp1716ToolsList($abilities);
        $easyMcpTools = $response['result']['tools'];

        $request = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp/'; }
            public function get_json_params(): array { return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']; }
        };
        $response = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => $easyMcpTools]];

        $final = EasyMcpNativeFileCompatibilityAdapter::projectFinalToolsListDescriptor($response, null, $request);
        $tools = array_column($final['result']['tools'], null, 'name');

        self::assertArrayHasKey('capture_id', $tools[self::TARGET]['inputSchema']['properties']);
        self::assertSame('string', $tools[self::TARGET]['inputSchema']['properties']['capture_id']['type']);
        self::assertSame('uuid', $tools[self::TARGET]['inputSchema']['properties']['capture_id']['format']);
        self::assertSame([
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 1,
            'items' => ['type' => 'string', 'enum' => ['video']],
        ], $tools[self::TARGET]['inputSchema']['properties']['resume_children']);
        self::assertNotContains('capture_id', $tools[self::TARGET]['inputSchema']['required']);
        self::assertSame(['idempotency_key', 'documentation_checkpoint'], $tools[self::TARGET]['inputSchema']['required']);
        self::assertSame('array', $tools[self::TARGET]['inputSchema']['properties']['files']['type']);
        self::assertSame('object', $tools[self::TARGET]['inputSchema']['properties']['files']['items']['type']);
        self::assertSame('binary', $tools[self::TARGET]['inputSchema']['properties']['files']['items']['format']);
        self::assertSame(['files'], $tools[self::TARGET]['_meta']['openai/fileParams']);
        self::assertSame($easyMcpTools[1], $tools['wp_ability_nhk_v3_media_ingest']);
    }

    public function test_rest_post_dispatch_projects_capture_schema_before_final_echo(): void
    {
        $request = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp'; }
            public function get_json_params(): array { return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']; }
        };
        $response = new class {
            /** @var array<string,mixed> */
            private array $data = ['result' => ['tools' => [[
                'name' => 'wp_ability_nhk_v3_capture_ingest',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'idempotency_key' => ['type' => 'string'],
                    'documentation_checkpoint' => ['type' => 'object'],
                    'text' => ['type' => 'string'],
                    'files' => ['type' => 'array'],
                ], 'required' => ['idempotency_key', 'documentation_checkpoint']],
            ]]]];

            /** @return array<string,mixed> */
            public function get_data(): array { return $this->data; }

            /** @param array<string,mixed> $data */
            public function set_data(array $data): void { $this->data = $data; }
        };

        $projected = EasyMcpNativeFileCompatibilityAdapter::projectToolsListDescriptor($response, null, $request);
        $capture = $projected->get_data()['result']['tools'][0];
        $properties = $capture['inputSchema']['properties'];

        self::assertArrayHasKey('capture_id', $properties);
        self::assertSame([
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 1,
            'items' => ['type' => 'string', 'enum' => ['video']],
        ], $properties['resume_children']);
        self::assertSame(['EDITORIAL', 'AUTHORITY', 'MIXED'], $properties['purpose']['enum']);
        self::assertSame(['PLAN', 'APPLY_APPROVED_PLAN'], $properties['authority_intent']['properties']['mode']['enum']);
        self::assertArrayHasKey('approved_plan_fingerprint', $properties['authority_intent']['properties']);
        self::assertArrayHasKey('approved_candidate_ids', $properties['authority_intent']['properties']);
        self::assertSame(['files'], $capture['_meta']['openai/fileParams']);
    }

    /**
     * Minimal executable model of Easy MCP 1.7.16's ability registration,
     * definition materialization, tools/list sanitization, and JSON-RPC
     * response construction. It intentionally does not project NHK metadata.
     *
     * @param array<string,array<string,mixed>> $abilities
     * @return array<string,mixed>
     */
    private static function simulateEasyMcp1716ToolsList(array $abilities): array
    {
        $tools = [];
        foreach ($abilities as $slug => $ability) {
            $inputSchema = $ability['input_schema'];
            if (!is_array($inputSchema) || $inputSchema === []) {
                $inputSchema = ['type' => 'object', 'properties' => new \stdClass()];
            } else {
                $inputSchema['type'] = 'object';
                $inputSchema['properties'] ??= new \stdClass();
                $inputSchema = self::normalizeEasyMcpSchema($inputSchema);
            }

            $tools[] = [
                'name' => 'wp_ability_' . trim((string) preg_replace('/[^a-z0-9]+/i', '_', strtolower($slug)), '_'),
                'description' => $ability['description'] . ' (ability: ' . $slug . ')',
                'inputSchema' => $inputSchema,
                'annotations' => ['title' => $ability['label'], 'openWorldHint' => true],
            ];
        }

        return ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => $tools]];
    }

    /** @param array<string,mixed> $schema @return array<string,mixed> */
    private static function normalizeEasyMcpSchema(array $schema): array
    {
        foreach (['properties', 'patternProperties', '$defs', 'definitions'] as $key) {
            if (!is_array($schema[$key] ?? null)) continue;
            foreach ($schema[$key] as $name => $child) {
                if (is_array($child)) $schema[$key][$name] = self::normalizeEasyMcpSchema($child);
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $key) {
            if (!is_array($schema[$key] ?? null)) continue;
            foreach ($schema[$key] as $index => $child) {
                if (is_array($child)) $schema[$key][$index] = self::normalizeEasyMcpSchema($child);
            }
        }
        return $schema;
    }

    public function test_only_supported_easy_mcp_versions_are_enabled(): void
    {
        self::assertTrue(EasyMcpNativeFileCompatibilityAdapter::isSupportedVersion('1.7.16'));
        self::assertTrue(EasyMcpNativeFileCompatibilityAdapter::isSupportedVersion('1.7.17'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::isSupportedVersion('1.7.18'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::isSupportedVersion('2.0.0'));
    }

    public function test_version_diagnostic_is_explicit_and_fail_closed(): void
    {
        self::assertSame([
            'code' => 'EASY_MCP_NATIVE_FILE_COMPAT_ACTIVE',
            'version' => '1.7.16',
            'native_file_support_upstream' => false,
        ], EasyMcpNativeFileCompatibilityAdapter::diagnosticForVersion('1.7.16'));
        self::assertSame('EASY_MCP_VERSION_UNSUPPORTED', EasyMcpNativeFileCompatibilityAdapter::diagnosticForVersion('1.7.18')['code']);
    }

    public function test_multipart_scope_requires_exact_endpoint_target_and_native_file_parts(): void
    {
        $rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => self::TARGET, 'arguments' => ['text' => 'x']]];
        $files = ['files' => ['name' => ['one.jpg'], 'tmp_name' => ['/tmp/native.jpg'], 'type' => ['image/jpeg'], 'size' => [12], 'error' => [0]]];

        self::assertTrue(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, $files, '1.7.16'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/nhk/v1/mcp', $rpc, $files, '1.7.16'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, [], '1.7.16'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, $files, '1.7.18'));

        self::assertTrue(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, ['files' => [
            ['name' => 'one.jpg', 'tmp_name' => '/tmp/one.jpg', 'type' => 'image/jpeg', 'size' => 12, 'error' => 0],
            ['name' => 'two.jpg', 'tmp_name' => '/tmp/two.jpg', 'type' => 'image/jpeg', 'size' => 13, 'error' => 0],
        ]], '1.7.16'));
    }

    public function test_only_uploaded_file_structures_with_successful_tmp_name_are_native_files(): void
    {
        $rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => self::TARGET]];

        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, ['files' => ['name' => 'fake.jpg']], '1.7.16'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, ['files' => [['name' => 'fake.jpg']]], '1.7.16'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, ['files' => ['tmp_name' => '']], '1.7.16'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, ['files' => ['tmp_name' => '/tmp/no-file', 'error' => UPLOAD_ERR_NO_FILE]], '1.7.16'));
        self::assertTrue(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, ['files' => ['tmp_name' => '/tmp/ok-file', 'error' => UPLOAD_ERR_OK]], '1.7.16'));
    }

    public function test_parallel_php_files_and_list_of_file_objects_are_supported(): void
    {
        $rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => self::TARGET]];

        self::assertTrue(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, ['files' => [
            'name' => ['one.jpg', 'two.jpg'],
            'tmp_name' => ['/tmp/one.jpg', '/tmp/two.jpg'],
            'type' => ['image/jpeg', 'image/jpeg'],
            'size' => [12, 13],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
        ]], '1.7.16'));
        self::assertTrue(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, ['files' => [
            ['name' => 'one.jpg', 'tmp_name' => '/tmp/one.jpg', 'type' => 'image/jpeg', 'size' => 12, 'error' => UPLOAD_ERR_OK],
            ['name' => 'two.jpg', 'tmp_name' => '/tmp/two.jpg', 'type' => 'image/jpeg', 'size' => 13, 'error' => UPLOAD_ERR_OK],
        ]], '1.7.16'));
    }

    public function test_json_path_and_base64_arguments_do_not_activate_multipart_adapter(): void
    {
        $path = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => self::TARGET, 'arguments' => ['files' => ['/tmp/x.jpg']]]];
        $base64 = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => self::TARGET, 'arguments' => ['content_base64' => 'ZmFrZQ==']]];

        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $path, [], '1.7.16'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $base64, [], '1.7.16'));
    }

    public function test_text_only_capture_and_internal_writers_are_not_intercepted(): void
    {
        $textOnly = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => self::TARGET, 'arguments' => ['text' => 'x']]];
        $internal = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'wp_ability_nhk_v3_media_ingest', 'arguments' => []]];
        $files = ['files' => ['name' => ['one.jpg'], 'tmp_name' => ['/tmp/native.jpg'], 'type' => ['image/jpeg'], 'size' => [12], 'error' => [0]]];

        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $textOnly, [], '1.7.16'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $internal, $files, '1.7.16'));
    }

    public function test_native_capture_files_are_normalized_before_ability_validation(): void
    {
        $input = [
            'idempotency_key' => 'capture-native-file-test',
            'documentation_checkpoint' => ['documentation_version' => 'doc', 'manifest_hash' => 'hash'],
            'files' => ['file_000000009f0082119bfda7a3663e9084'],
        ];
        $files = ['files' => [
            'name' => ['camera.jpg'],
            'type' => ['image/jpeg'],
            'tmp_name' => ['/tmp/php-native-camera'],
            'error' => [UPLOAD_ERR_OK],
            'size' => [1234],
        ]];

        $normalized = EasyMcpNativeFileCompatibilityAdapter::normalizeNativeFileInput(
            $input,
            'nhk-v3/capture-ingest',
            $files,
            '1.7.16'
        );

        self::assertSame([
            [
                'name' => 'camera.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/tmp/php-native-camera',
                'error' => UPLOAD_ERR_OK,
                'size' => 1234,
            ],
        ], $normalized['files']);
        self::assertNotSame(['file_000000009f0082119bfda7a3663e9084'], $normalized['files']);
    }

    public function test_direct_easy_mcp_1717_normalization_preserves_native_file_order_and_cardinality(): void
    {
        $input = [
            'idempotency_key' => 'capture-native-files-test',
            'documentation_checkpoint' => ['documentation_version' => 'doc', 'manifest_hash' => 'hash'],
            'files' => ['file-one', 'file-two'],
            'items' => [
                ['client_file_id' => 'file-one'],
                ['client_file_id' => 'file-two'],
            ],
        ];
        $files = ['files' => [
            'name' => ['first.jpg', 'second.jpg'],
            'type' => ['image/jpeg', 'image/png'],
            'tmp_name' => ['/tmp/first', '/tmp/second'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [101, 202],
        ]];

        $normalized = EasyMcpNativeFileCompatibilityAdapter::normalizeNativeFileInput($input, 'nhk-v3/capture-ingest', $files, '1.7.17');

        self::assertSame(['first.jpg', 'second.jpg'], array_column($normalized['files'], 'name'));
        self::assertSame(['/tmp/first', '/tmp/second'], array_column($normalized['files'], 'tmp_name'));
        self::assertSame($input['items'], $normalized['items']);
        self::assertCount(2, $normalized['files']);
    }

    public function test_native_file_alignment_rejects_a_different_number_of_connector_items(): void
    {
        $input = [
            'idempotency_key' => 'capture-native-files-mismatch',
            'files' => ['only-one-file-id'],
        ];
        $files = ['files' => [
            'name' => ['first.jpg', 'second.jpg'],
            'tmp_name' => ['/tmp/first', '/tmp/second'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [101, 202],
        ]];

        $normalized = EasyMcpNativeFileCompatibilityAdapter::normalizeNativeFileInput($input, 'nhk-v3/capture-ingest', $files, '1.7.17');

        // WordPress supplies WP_Error here. The framework-only unit suite has
        // no WordPress bootstrap, so the pure fallback remains unchanged.
        if (class_exists('WP_Error')) {
            self::assertInstanceOf('WP_Error', $normalized);
            self::assertSame('nhk_native_multipart_alignment', $normalized->get_error_code());
        } else {
            self::assertSame($input, $normalized);
        }
    }

    public function test_native_capture_files_are_not_put_into_canonical_json_arguments(): void
    {
        $arguments = McpAbilityRegistration::canonicalTransportArguments('nhk.capture.ingest', [
            'text' => 'caption',
            'files' => [[
                'name' => 'camera.jpg',
                'tmp_name' => '/tmp/php-native-camera',
                'type' => 'image/jpeg',
                'error' => UPLOAD_ERR_OK,
                'size' => 1234,
            ]],
        ]);

        self::assertSame(['text' => 'caption'], $arguments);
    }

    public function test_nonempty_file_input_requires_native_transport_parts(): void
    {
        self::assertTrue(McpAbilityRegistration::requiresNativeTransportFiles('nhk.capture.ingest', [
            'files' => [['name' => 'camera.jpg', 'tmp_name' => '/tmp/not-a-wire-part']],
        ], []));
        self::assertFalse(McpAbilityRegistration::requiresNativeTransportFiles('nhk.capture.ingest', ['files' => []], []));
        self::assertFalse(McpAbilityRegistration::requiresNativeTransportFiles('nhk.capture.ingest', ['text' => 'caption'], []));
    }

    public function test_native_file_scope_makes_request_files_available_to_ability_and_restores_global_state(): void
    {
        $files = ['files' => [
            'name' => ['fixture.jpg'],
            'tmp_name' => ['/tmp/fixture.jpg'],
            'type' => ['image/jpeg'],
            'size' => [12],
            'error' => [UPLOAD_ERR_OK],
        ]];
        $previous = $_FILES ?? [];

        $reflection = new \ReflectionClass(EasyMcpNativeFileCompatibilityAdapter::class);
        $method = $reflection->getMethod('withNativeFiles');
        $method->setAccessible(true);
        $seen = $method->invoke(null, $files, static function (): array {
            return $_FILES;
        });

        self::assertSame($files, $seen);
        self::assertSame($previous, $_FILES ?? []);
    }
}
