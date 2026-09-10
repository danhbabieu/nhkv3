<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Mcp\EasyMcpNativeFileCompatibilityAdapter;
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

    public function test_tools_list_projection_keeps_capture_descriptor_on_newer_or_unreported_easy_mcp_versions(): void
    {
        $request = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp/'; }
            public function get_param(string $key): mixed { return null; }
            public function get_json_params(): ?array { return null; }
        };
        $response = new class {
            private array $data = ['result' => ['tools' => [[
                'name' => 'wp_ability_nhk_v3_capture_ingest',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ], [
                'name' => 'wp_ability_nhk_v3_media_ingest',
                'inputSchema' => ['type' => 'object'],
            ]]]];
            public function get_data(): array { return $this->data; }
            public function set_data(array $data): void { $this->data = $data; }
        };

        $projected = EasyMcpNativeFileCompatibilityAdapter::projectToolsListDescriptor($response, null, $request);
        $tools = array_column($projected->get_data()['result']['tools'], null, 'name');

        self::assertArrayHasKey('capture_id', $tools[self::TARGET]['inputSchema']['properties']);
        self::assertSame(['files'], $tools[self::TARGET]['_meta']['openai/fileParams']);
        self::assertArrayNotHasKey('_meta', $tools['wp_ability_nhk_v3_media_ingest']);
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
