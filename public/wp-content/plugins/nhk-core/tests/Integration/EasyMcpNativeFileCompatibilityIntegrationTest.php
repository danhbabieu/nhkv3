<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Infrastructure\Mcp\EasyMcpNativeFileCompatibilityAdapter;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class EasyMcpNativeFileCompatibilityIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        require_once dirname(__DIR__, 2) . '/nhk-core.php';
        do_action('rest_api_init');
        if (!class_exists('Easy_MCP_AI\\MCP\\Transport')) self::markTestSkipped('Easy MCP AI is required for the native-file compatibility integration assertion.');
    }

    public function test_final_descriptor_projection_runs_at_wordpress_json_boundary_without_adding_tools(): void
    {
        if (!defined('EASY_MCP_AI_VERSION')) self::markTestSkipped('Easy MCP AI version constant is unavailable.');

        $request = new \WP_REST_Request('POST', '/easy-mcp-ai/v1/mcp');
        $request->set_body((string) wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
        $data = ['result' => ['tools' => [[
            'name' => 'wp_ability_nhk_v3_capture_ingest',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ], [
            'name' => 'wp_ability_nhk_v3_media_ingest',
            'inputSchema' => ['type' => 'object'],
        ]]]];

        $projected = EasyMcpNativeFileCompatibilityAdapter::projectFinalToolsListDescriptor($data, rest_get_server(), $request);
        $tools = array_column($projected['result']['tools'], null, 'name');

        self::assertArrayHasKey('capture_id', $tools['wp_ability_nhk_v3_capture_ingest']['inputSchema']['properties']);
        self::assertSame([
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 1,
            'items' => ['type' => 'string', 'enum' => ['video']],
        ], $tools['wp_ability_nhk_v3_capture_ingest']['inputSchema']['properties']['resume_children']);
        self::assertSame(['files'], $tools['wp_ability_nhk_v3_capture_ingest']['_meta']['openai/fileParams']);
        self::assertArrayNotHasKey('_meta', $tools['wp_ability_nhk_v3_media_ingest']);
    }

    public function test_unauthenticated_multipart_capture_is_denied_before_nhk_dispatch(): void
    {
        if (!defined('EASY_MCP_AI_VERSION')) self::markTestSkipped('Easy MCP AI version constant is unavailable.');

        $request = new \WP_REST_Request('POST', '/easy-mcp-ai/v1/mcp');
        $request->set_param('request', (string) wp_json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'wp_ability_nhk_v3_capture_ingest', 'arguments' => ['text' => 'probe']],
        ]));
        $request->set_file_params(['files' => [
            'name' => ['probe.jpg'],
            'type' => ['image/jpeg'],
            'tmp_name' => ['/tmp/nhk-native-file-probe.jpg'],
            'error' => [0],
            'size' => [1],
        ]]);

        $previousFiles = $_FILES ?? [];
        $_FILES = $request->get_file_params();
        try {
            $response = EasyMcpNativeFileCompatibilityAdapter::interceptMultipartCapture(null, [], $request);
            self::assertInstanceOf(\WP_REST_Response::class, $response);
            self::assertSame(401, $response->get_status());
        } finally {
            $_FILES = $previousFiles;
        }
    }
}
