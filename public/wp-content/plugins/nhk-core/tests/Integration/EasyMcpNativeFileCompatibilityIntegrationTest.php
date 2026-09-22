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

    public function test_wordpress_bootstrap_and_mcp_registration_emit_no_php_warnings_or_notices(): void
    {
        $errors = [];
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$errors): bool {
            if ($severity === E_WARNING || $severity === E_NOTICE || $severity === E_USER_WARNING || $severity === E_USER_NOTICE) {
                $errors[] = [$message, $file, $line];
                return true;
            }
            return false;
        });

        try {
            do_action('wp_abilities_api_init');
            do_action('rest_api_init');
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $errors, (string) wp_json_encode($errors));
    }

    public function test_easy_mcp_1718_authenticated_rest_wire_projects_resources_list_and_read_after_native_registry(): void
    {
        if (!defined('EASY_MCP_AI_VERSION') || EASY_MCP_AI_VERSION !== '1.7.18') {
            self::markTestSkipped('Easy MCP AI 1.7.18 is required for the authenticated wire regression.');
        }

        $users = get_users(['role' => 'administrator', 'number' => 1]);
        self::assertNotEmpty($users);
        $tokenManager = new \Easy_MCP_AI\Auth\Token_Manager();
        $token = $tokenManager->create_token('nhk-resource-wire-regression', (int) $users[0]->ID, ['*']);
        self::assertIsArray($token);
        $rawToken = (string) ($token['raw_token'] ?? '');
        self::assertNotSame('', $rawToken);

        $preProjection = [];
        $captureNative = static function (mixed $response, mixed $server, mixed $request) use (&$preProjection): mixed {
            if (is_object($request) && method_exists($request, 'get_json_params') && ($request->get_json_params()['method'] ?? null) === 'resources/read' && is_object($response) && method_exists($response, 'get_data')) {
                $preProjection = $response->get_data();
            }
            return $response;
        };
        add_filter('rest_post_dispatch', $captureNative, 9, 3);

        try {
            $discoverResponse = $this->dispatchAuthenticatedWire($rawToken, 'server/discover', 400, null);
            $discoverWire = $this->wireBody($discoverResponse);
            self::assertSame('2.0', $discoverWire['jsonrpc']);
            self::assertSame(['mimeTypes' => ['text/html;profile=mcp-app']], $discoverWire['result']['capabilities']['extensions']['io.modelcontextprotocol/ui'] ?? null);
            self::assertArrayHasKey('tools', $discoverWire['result']['capabilities']);
            self::assertArrayHasKey('resources', $discoverWire['result']['capabilities']);
            self::assertArrayHasKey('serverInfo', $discoverWire['result']);

            $initializeResponse = $this->dispatchAuthenticatedWire($rawToken, 'initialize', 401, null);
            $initializeWire = $this->wireBody($initializeResponse);
            self::assertSame('2026-07-28', $initializeWire['result']['protocolVersion']);
            self::assertSame(['mimeTypes' => ['text/html;profile=mcp-app']], $initializeWire['result']['capabilities']['extensions']['io.modelcontextprotocol/ui'] ?? null);

            $listResponse = $this->dispatchAuthenticatedWire($rawToken, 'resources/list', 401, null);
            $listWire = $this->wireBody($listResponse);
            self::assertSame('2.0', $listWire['jsonrpc']);
            self::assertSame(401, $listWire['id']);
            $resources = array_column($listWire['result']['resources'] ?? [], null, 'uri');
            self::assertSame('text/html;profile=mcp-app', $resources['ui://nhk/image-upload/v3.html']['mimeType'] ?? null);

            $readResponse = $this->dispatchAuthenticatedWire($rawToken, 'resources/read', 402, 'ui://nhk/image-upload/v3.html');
            $readWire = $this->wireBody($readResponse);
            self::assertSame('2.0', $readWire['jsonrpc']);
            self::assertSame(402, $readWire['id']);
            self::assertArrayNotHasKey('error', $readWire);
            self::assertSame('ui://nhk/image-upload/v3.html', $readWire['result']['contents'][0]['uri']);
            self::assertSame('text/html;profile=mcp-app', $readWire['result']['contents'][0]['mimeType']);
            self::assertNotSame('', trim((string) ($readWire['result']['contents'][0]['text'] ?? '')));
            self::assertArrayHasKey('error', $preProjection, 'Easy MCP must produce its native Resource not found error before NHK post-dispatch projection.');
            self::assertSame('Resource not found', $preProjection['error']['message'] ?? null);

            $unknownResponse = $this->dispatchAuthenticatedWire($rawToken, 'resources/read', 403, 'ui://nhk/image-upload/unknown.html');
            $unknownWire = $this->wireBody($unknownResponse);
            self::assertArrayHasKey('error', $unknownWire);
            self::assertArrayNotHasKey('result', $unknownWire);
        } finally {
            remove_filter('rest_post_dispatch', $captureNative, 9);
            $tokenManager->delete_token((int) ($token['id'] ?? 0));
        }
    }

    private function dispatchAuthenticatedWire(string $rawToken, string $method, int $id, ?string $uri): mixed
    {
        $request = new \WP_REST_Request('POST', '/easy-mcp-ai/v1/mcp');
        $params = [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ],
        ];
        if ($uri !== null) $params['uri'] = $uri;
        $request->set_header('Authorization', 'Bearer ' . $rawToken);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json');
        $request->set_header('MCP-Protocol-Version', '2026-07-28');
        $request->set_header('Mcp-Method', $method);
        if ($uri !== null) $request->set_header('Mcp-Name', $uri);
        $request->set_body((string) wp_json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]));
        $response = rest_get_server()->dispatch($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);
        return $response;
    }

    /** @return array<string,mixed> */
    private function wireBody(mixed $response): array
    {
        $json = wp_json_encode($response->get_data());
        self::assertIsString($json);
        self::assertStringNotContainsString('Warning:', $json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
