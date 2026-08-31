<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class McpTransportIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        require_once dirname(__DIR__, 2) . '/nhk-core.php';
        do_action('rest_api_init');
    }

    public function test_modern_streamable_http_tools_list_uses_json_rpc_and_catalog_schema(): void
    {
        $response = $this->request('tools/list', ['id' => 1]);
        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertSame('2.0', $data['jsonrpc']);
        self::assertCount(11, $data['result']['tools']);
        self::assertSame(['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q'], 'additionalProperties' => false], $data['result']['tools'][0]['inputSchema']);
    }

    public function test_modern_header_body_mismatch_is_rejected(): void
    {
        $response = $this->request('tools/list', ['id' => 2], ['Mcp-Method' => 'tools/call']);
        self::assertSame(400, $response->get_status());
        self::assertSame(-32020, $response->get_data()['error']['code']);
    }

    public function test_unauthenticated_governed_tool_call_is_rejected(): void
    {
        $response = $this->request('tools/call', ['id' => 3, 'params' => ['name' => 'nhk.proposal.create', 'arguments' => ['operation' => 'create', 'payload' => ['name' => 'blocked']]]], ['Mcp-Name' => 'nhk.proposal.create']);
        self::assertSame(403, $response->get_status());
        self::assertSame(-32003, $response->get_data()['error']['code']);
    }

    private function request(string $method, array $body, array $extraHeaders = []): \WP_REST_Response
    {
        $body = array_merge(['jsonrpc' => '2.0', 'method' => $method], $body);
        $request = new \WP_REST_Request('POST', '/nhk/v1/mcp');
        $request->set_header('MCP-Protocol-Version', '2026-07-28');
        $request->set_header('Mcp-Method', $method);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        foreach ($extraHeaders as $name => $value) $request->set_header($name, $value);
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $params['_meta']['io.modelcontextprotocol/protocolVersion'] = '2026-07-28';
        $body['params'] = $params;
        $request->set_body(wp_json_encode($body));
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);
        return $response;
    }
}
