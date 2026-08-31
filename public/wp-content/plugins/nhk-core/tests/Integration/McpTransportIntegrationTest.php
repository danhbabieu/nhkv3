<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Governance\GovernanceCapabilities;
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository};
use NHK\Core\Infrastructure\Governance\WpdbProposalRepository;
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
        self::assertCount(13, $data['result']['tools']);
        self::assertSame(['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q'], 'additionalProperties' => false], $data['result']['tools'][0]['inputSchema']);
    }

    public function test_modern_header_body_mismatch_is_rejected(): void
    {
        $response = $this->request('tools/list', ['id' => 2], ['Mcp-Method' => 'tools/call']);
        self::assertSame(400, $response->get_status());
        self::assertSame(-32020, $response->get_data()['error']['code']);
    }

    public function test_modern_streamable_http_requires_both_response_media_types(): void
    {
        $response = $this->request('tools/list', ['id' => 21], ['Accept' => 'application/json']);
        self::assertSame(400, $response->get_status());
        self::assertSame(-32020, $response->get_data()['error']['code']);
    }

    public function test_unauthenticated_governed_tool_call_is_rejected(): void
    {
        $response = $this->request('tools/call', ['id' => 3, 'params' => ['name' => 'nhk.proposal.create', 'arguments' => ['operation' => 'create', 'payload' => ['name' => 'blocked']]]], ['Mcp-Name' => 'nhk.proposal.create']);
        self::assertSame(403, $response->get_status());
        self::assertSame(-32003, $response->get_data()['error']['code']);
    }

    public function test_unauthenticated_media_ingest_is_rejected_before_proposal_creation(): void
    {
        $response = $this->request('tools/call', ['id' => 4, 'params' => ['name' => 'nhk.media.ingest', 'arguments' => ['stable_key' => 'blocked-media', 'name' => 'Blocked Media']]], ['Mcp-Name' => 'nhk.media.ingest']);
        self::assertSame(403, $response->get_status());
        self::assertSame(-32003, $response->get_data()['error']['code']);
    }

    public function test_authenticated_media_ingest_runs_through_governed_lifecycle_and_keeps_asset_private(): void
    {
        $users = get_users(['role' => 'administrator', 'number' => 1]);
        if ($users === []) {
            $createdUser = wp_insert_user(['user_login' => 'nhk-mcp-' . bin2hex(random_bytes(4)), 'user_pass' => wp_generate_password(32, true, true), 'user_email' => 'nhk-mcp-' . bin2hex(random_bytes(4)) . '@example.test', 'role' => 'administrator']);
            if (is_wp_error($createdUser)) self::fail($createdUser->get_error_message());
            $users = [get_user_by('id', (int) $createdUser)];
        }
        self::assertNotNull($users[0]);
        GovernanceCapabilities::register();
        $previousUser = get_current_user_id();
        wp_set_current_user((int) $users[0]->ID);
        global $wpdb;
        $stableKey = 'mcp-media-' . bin2hex(random_bytes(4));
        $assetChecksum = hash('sha256', $stableKey);
        try {
            $create = $this->request('tools/call', ['id' => 5, 'params' => ['name' => 'nhk.media.ingest', 'arguments' => [
                'stable_key' => $stableKey,
                'name' => 'MCP ' . $stableKey,
                'readiness' => 'draft',
                'provenance' => ['source' => 'mcp-integration-test'],
                'assets' => [['kind' => 'original', 'storage_key' => 'uploads/mcp/' . $stableKey . '.jpg', 'checksum' => $assetChecksum, 'mime_type' => 'image/jpeg', 'byte_size' => 12, 'width' => 1200, 'height' => 800]],
                'usages' => [['endpoint_type' => 'wp_post', 'endpoint_key' => '1:42', 'role' => 'gallery']],
            ]]], ['Mcp-Name' => 'nhk.media.ingest']);
            self::assertSame(200, $create->get_status(), (string) wp_json_encode($create->get_data()));
            $created = $create->get_data()['result']['structuredContent'];
            self::assertFalse($create->get_data()['result']['isError']);
            self::assertSame('media', $created['entity_type']);
            self::assertSame('ingest', $created['operation']);
            $proposalId = (string) $created['id'];
            $proposal = (new WpdbProposalRepository($wpdb))->find($proposalId);
            self::assertNotNull($proposal);

            $submit = $this->request('tools/call', ['id' => 6, 'params' => ['name' => 'nhk.proposal.submit', 'arguments' => ['id' => $proposalId]]], ['Mcp-Name' => 'nhk.proposal.submit']);
            self::assertSame(200, $submit->get_status());
            $approve = $this->request('tools/call', ['id' => 7, 'params' => ['name' => 'nhk.proposal.approve', 'arguments' => ['id' => $proposalId, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint]]], ['Mcp-Name' => 'nhk.proposal.approve']);
            self::assertSame(200, $approve->get_status());
            $apply = $this->request('tools/call', ['id' => 8, 'params' => ['name' => 'nhk.proposal.apply', 'arguments' => ['id' => $proposalId]]], ['Mcp-Name' => 'nhk.proposal.apply']);
            self::assertSame(200, $apply->get_status());
            $applied = $apply->get_data()['result']['structuredContent'];
            self::assertFalse($apply->get_data()['result']['isError'], (string) wp_json_encode($apply->get_data()));
            self::assertNotEmpty($applied['result_entity_uuid']);

            $media = (new WpdbMediaRepository($wpdb))->findByCanonicalId((string) $applied['result_entity_uuid']);
            self::assertNotNull($media);
            self::assertSame('draft', $media->readiness);
            $assets = (new WpdbMediaAssetRepository($wpdb))->listByMediaId($media->canonicalId);
            self::assertCount(1, $assets);
            self::assertSame('PRIVATE', $assets[0]->visibility);
        } finally {
            wp_set_current_user($previousUser);
        }
    }

    public function test_authenticated_video_ingest_runs_through_governed_lifecycle(): void
    {
        $users = get_users(['role' => 'administrator', 'number' => 1]);
        self::assertNotEmpty($users);
        GovernanceCapabilities::register();
        $previousUser = get_current_user_id();
        wp_set_current_user((int) $users[0]->ID);
        global $wpdb;
        $videoId = substr(bin2hex(random_bytes(8)), 0, 11);
        try {
            $create = $this->request('tools/call', ['id' => 9, 'params' => ['name' => 'nhk.video.ingest', 'arguments' => [
                'url' => 'https://youtu.be/' . $videoId,
                'title' => 'MCP video ' . $videoId,
                'metadata' => ['source' => 'mcp-integration-test'],
            ]]], ['Mcp-Name' => 'nhk.video.ingest']);
            self::assertSame(200, $create->get_status(), (string) wp_json_encode($create->get_data()));
            $created = $create->get_data()['result']['structuredContent'];
            self::assertFalse($create->get_data()['result']['isError']);
            self::assertSame('video', $created['entity_type']);
            self::assertSame('ingest', $created['operation']);
            $proposalId = (string) $created['id'];
            $proposal = (new WpdbProposalRepository($wpdb))->find($proposalId);
            self::assertNotNull($proposal);

            $submit = $this->request('tools/call', ['id' => 10, 'params' => ['name' => 'nhk.proposal.submit', 'arguments' => ['id' => $proposalId]]], ['Mcp-Name' => 'nhk.proposal.submit']);
            self::assertSame(200, $submit->get_status());
            $approve = $this->request('tools/call', ['id' => 11, 'params' => ['name' => 'nhk.proposal.approve', 'arguments' => ['id' => $proposalId, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint]]], ['Mcp-Name' => 'nhk.proposal.approve']);
            self::assertSame(200, $approve->get_status());
            $apply = $this->request('tools/call', ['id' => 12, 'params' => ['name' => 'nhk.proposal.apply', 'arguments' => ['id' => $proposalId]]], ['Mcp-Name' => 'nhk.proposal.apply']);
            self::assertSame(200, $apply->get_status());
            $applied = $apply->get_data()['result']['structuredContent'];
            self::assertFalse($apply->get_data()['result']['isError'], (string) wp_json_encode($apply->get_data()));
            self::assertNotEmpty($applied['result_entity_uuid']);

            $video = (new \NHK\Core\Infrastructure\Video\WpdbVideoRepository($wpdb))->findByCanonicalId((string) $applied['result_entity_uuid']);
            self::assertNotNull($video);
            self::assertSame('youtube', $video->platform);
            self::assertSame($videoId, $video->externalVideoId);
            self::assertTrue($video->active);
            $read = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/video/' . $video->canonicalId));
            self::assertSame(200, $read->get_status());
            self::assertSame($video->canonicalId, $read->get_data()['id']);
            self::assertSame($videoId, $read->get_data()['external_id']);
        } finally {
            wp_set_current_user($previousUser);
        }
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
