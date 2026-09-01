<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Governance\GovernanceCapabilities;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository};
use NHK\Core\Domain\Media\Media;
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
        self::assertCount(18, $data['result']['tools']);
        self::assertSame(['type' => 'object', 'properties' => ['q' => ['type' => 'string'], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]], 'required' => ['q'], 'additionalProperties' => false], $data['result']['tools'][0]['inputSchema']);
    }

    public function test_tools_call_enforces_required_and_uuid_schema_arguments(): void
    {
        $missing = $this->request('tools/call', ['id' => 22, 'params' => ['name' => 'nhk.entity.get', 'arguments' => ['type' => 'brand']]], ['Mcp-Name' => 'nhk.entity.get']);
        self::assertSame(400, $missing->get_status());
        self::assertSame(-32602, $missing->get_data()['error']['code']);
        $invalid = $this->request('tools/call', ['id' => 23, 'params' => ['name' => 'nhk.entity.get', 'arguments' => ['type' => 'brand', 'id' => '00000000-0000-0000-0000-000000000000']]], ['Mcp-Name' => 'nhk.entity.get']);
        self::assertSame(400, $invalid->get_status());
        self::assertSame(-32602, $invalid->get_data()['error']['code']);
        $uppercase = $this->request('tools/call', ['id' => 24, 'params' => ['name' => 'nhk.entity.get', 'arguments' => ['type' => 'brand', 'id' => '550E8400-E29B-41D4-A716-446655440000']]], ['Mcp-Name' => 'nhk.entity.get']);
        self::assertSame(200, $uppercase->get_status());
        self::assertFalse($uppercase->get_data()['result']['isError']);
        self::assertNull($uppercase->get_data()['result']['structuredContent']);
    }

    public function test_standard_modern_initialize_accepts_protocol_version_in_params_without_custom_headers(): void
    {
        $request = new \WP_REST_Request('POST', '/nhk/v1/mcp');
        $request->set_header('MCP-Protocol-Version', '2026-07-28');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        $request->set_body(wp_json_encode(['jsonrpc' => '2.0', 'id' => 19, 'method' => 'initialize', 'params' => ['protocolVersion' => '2026-07-28', 'capabilities' => [], 'clientInfo' => ['name' => 'standard-client', 'version' => '1.0']]]));
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());
        self::assertSame('2026-07-28', $response->get_data()['result']['protocolVersion']);
    }

    public function test_standard_modern_tools_list_accepts_header_only_after_initialize(): void
    {
        $request = new \WP_REST_Request('POST', '/nhk/v1/mcp');
        $request->set_header('MCP-Protocol-Version', '2026-07-28');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        $request->set_body(wp_json_encode(['jsonrpc' => '2.0', 'id' => 20, 'method' => 'tools/list', 'params' => []]));
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());
        self::assertCount(18, $response->get_data()['result']['tools']);
    }

    public function test_standard_modern_tools_call_accepts_header_only_without_custom_method_headers(): void
    {
        $request = new \WP_REST_Request('POST', '/nhk/v1/mcp');
        $request->set_header('MCP-Protocol-Version', '2026-07-28');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        $request->set_body(wp_json_encode(['jsonrpc' => '2.0', 'id' => 21, 'method' => 'tools/call', 'params' => ['name' => 'nhk.search', 'arguments' => ['q' => 'odo', 'page' => 1, 'per_page' => 1]]]));
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());
        self::assertFalse($response->get_data()['result']['isError']);
    }

    public function test_standard_initialized_notification_returns_202_without_a_response_body(): void
    {
        $request = new \WP_REST_Request('POST', '/nhk/v1/mcp');
        $request->set_header('MCP-Protocol-Version', '2026-07-28');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        $request->set_body(wp_json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => []]));
        $response = rest_do_request($request);
        self::assertSame(202, $response->get_status());
        self::assertNull($response->get_data());
    }

    public function test_mcp_protocol_headers_are_allowed_by_wordpress_cors_filter(): void
    {
        $headers = apply_filters('rest_allowed_cors_headers', ['Authorization', 'Content-Type']);
        self::assertContains('MCP-Protocol-Version', $headers);
        self::assertContains('Mcp-Method', $headers);
        self::assertContains('Mcp-Name', $headers);
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
            $invalid = $this->request('tools/call', ['id' => 50, 'params' => ['name' => 'nhk.media.ingest', 'arguments' => [
                'stable_key' => $stableKey . '-invalid',
                'name' => 'Invalid MCP media',
                'assets' => [['storage_key' => 'uploads/mcp/missing-kind.jpg']],
            ]]], ['Mcp-Name' => 'nhk.media.ingest']);
            self::assertSame(400, $invalid->get_status());
            self::assertSame(-32602, $invalid->get_data()['error']['code']);

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
            $read = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/media/' . $media->canonicalId));
            self::assertSame(404, $read->get_status());
        } finally {
            wp_set_current_user($previousUser);
        }
    }

    public function test_public_media_read_exposes_reader_safe_asset_url(): void
    {
        global $wpdb;
        $media = (new WpdbMediaRepository($wpdb))->create(new Media(\NHK\Core\Shared\Uuid\UuidCodec::newV7(), 'mcp-public-media-' . bin2hex(random_bytes(4)), 'Public Media', 'ready'));
        $upload = wp_upload_dir();
        $mediaRoot = is_array($upload) ? (string) ($upload['basedir'] ?? '') : '';
        $assetDirectory = $mediaRoot . '/public';
        wp_mkdir_p($assetDirectory);
        file_put_contents($assetDirectory . '/asset.jpg', 'public-asset');
        $asset = (new WpdbMediaAssetRepository($wpdb))->create(new \NHK\Core\Domain\Media\MediaAsset(\NHK\Core\Shared\Uuid\UuidCodec::newV7(), $media->canonicalId, 'original', 'public/asset.jpg', hash('sha256', 'public-asset'), 'image/jpeg', 12, 20, 10, 'PUBLIC'));
        try {
            $read = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/media/' . $media->canonicalId));
            self::assertSame(200, $read->get_status());
            self::assertSame('/media/asset/' . $asset->assetId . '/', $read->get_data()['assets'][0]['public_url']);
            self::assertArrayNotHasKey('storage_key', $read->get_data()['assets'][0]);
        } finally {
            unlink($assetDirectory . '/asset.jpg');
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media_assets WHERE asset_uuid=%s", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($asset->assetId)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_media WHERE canonical_uuid=%s", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($media->canonicalId)));
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
                'metadata' => ['source' => 'mcp-integration-test', 'visibility' => 'PUBLIC'],
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
            self::assertArrayNotHasKey('metadata', $read->get_data());
            self::assertArrayNotHasKey('thumbnail_media_id', $read->get_data());
            self::assertArrayNotHasKey('active', $read->get_data());
            self::assertArrayNotHasKey('revision', $read->get_data());
        } finally {
            wp_set_current_user($previousUser);
        }
    }

    public function test_authenticated_knowledge_source_and_evidence_ingest_run_through_governed_lifecycle(): void
    {
        $users = get_users(['role' => 'administrator', 'number' => 1]);
        self::assertNotEmpty($users);
        GovernanceCapabilities::register();
        $previousUser = get_current_user_id();
        wp_set_current_user((int) $users[0]->ID);
        global $wpdb;
        $suffix = bin2hex(random_bytes(4));
        try {
            $source = $this->governedIngest('nhk.source.ingest', [
                'stable_key' => 'mcp-source-' . $suffix,
                'title' => 'MCP source ' . $suffix,
                'source_type' => 'catalog',
                'locator' => 'https://example.test/mcp/' . $suffix,
                'metadata' => ['source' => 'mcp-integration-test', 'visibility' => 'PUBLIC'],
            ], 30);
            $claim = $this->governedIngest('nhk.knowledge.ingest', [
                'stable_key' => 'mcp-claim-' . $suffix,
                'text' => 'The object has a spring-driven movement.',
                'claim_type' => 'technical',
                'provenance' => ['source' => 'mcp-integration-test'],
            ], 40);
            $evidence = $this->governedIngest('nhk.evidence.ingest', [
                'claim_id' => (string) $claim['result_entity_uuid'],
                'source_id' => (string) $source['result_entity_uuid'],
                'excerpt' => 'Spring-driven movement',
                'relation' => 'supports',
                'locator' => 'https://example.test/mcp/' . $suffix . '#movement',
                'metadata' => ['source' => 'mcp-integration-test', 'visibility' => 'PUBLIC'],
            ], 50);

            self::assertNotEmpty($evidence['result_entity_uuid']);
            $claimRecord = (new WpdbKnowledgeRepository($wpdb))->findByCanonicalId((string) $claim['result_entity_uuid']);
            $sourceRecord = (new WpdbSourceRepository($wpdb))->findByCanonicalId((string) $source['result_entity_uuid']);
            $evidenceRecord = (new WpdbEvidenceRepository($wpdb))->findByCanonicalId((string) $evidence['result_entity_uuid']);
            self::assertNotNull($claimRecord);
            self::assertNotNull($sourceRecord);
            self::assertNotNull($evidenceRecord);
            self::assertSame('technical', $claimRecord->claimType);
            self::assertSame('catalog', $sourceRecord->sourceType);
            self::assertSame($claimRecord->canonicalId, $evidenceRecord->claimId);
            self::assertSame($sourceRecord->canonicalId, $evidenceRecord->sourceId);

            $claimRead = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/knowledge/claim/' . $claimRecord->canonicalId));
            $sourceRead = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/knowledge/source/' . $sourceRecord->canonicalId));
            $evidenceRead = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/knowledge/evidence/' . $evidenceRecord->canonicalId));
            self::assertSame(200, $claimRead->get_status());
            self::assertSame(200, $sourceRead->get_status());
            self::assertSame(200, $evidenceRead->get_status());
            self::assertCount(1, $claimRead->get_data()['evidence']);
            self::assertCount(1, $sourceRead->get_data()['evidence']);
            self::assertArrayNotHasKey('metadata', $sourceRead->get_data());
            self::assertArrayNotHasKey('metadata', $claimRead->get_data()['evidence'][0]);
            self::assertArrayNotHasKey('provenance', $claimRead->get_data());
            self::assertSame($evidenceRecord->canonicalId, $evidenceRead->get_data()['id']);
            self::assertSame($sourceRecord->title, $evidenceRead->get_data()['source_title']);
            self::assertArrayNotHasKey('metadata', $evidenceRead->get_data());

            $sourceMcp = $this->request('tools/call', ['id' => 60, 'params' => ['name' => 'nhk.source.get', 'arguments' => ['id' => $sourceRecord->canonicalId]]], ['Mcp-Name' => 'nhk.source.get']);
            $evidenceMcp = $this->request('tools/call', ['id' => 61, 'params' => ['name' => 'nhk.evidence.get', 'arguments' => ['id' => $evidenceRecord->canonicalId]]], ['Mcp-Name' => 'nhk.evidence.get']);
            self::assertSame(200, $sourceMcp->get_status());
            self::assertSame(200, $evidenceMcp->get_status());
            self::assertSame($sourceRecord->canonicalId, $sourceMcp->get_data()['result']['structuredContent']['id']);
            self::assertSame($evidenceRecord->canonicalId, $evidenceMcp->get_data()['result']['structuredContent']['id']);
            self::assertArrayNotHasKey('metadata', $evidenceMcp->get_data()['result']['structuredContent']);
        } finally {
            wp_set_current_user($previousUser);
        }
    }

    private function governedIngest(string $tool, array $arguments, int $id): array
    {
        global $wpdb;
        $create = $this->request('tools/call', ['id' => $id, 'params' => ['name' => $tool, 'arguments' => $arguments]], ['Mcp-Name' => $tool]);
        self::assertSame(200, $create->get_status(), (string) wp_json_encode($create->get_data()));
        $created = $create->get_data()['result']['structuredContent'];
        self::assertFalse($create->get_data()['result']['isError'], (string) wp_json_encode($create->get_data()));
        $proposalId = (string) $created['id'];
        $proposal = (new WpdbProposalRepository($wpdb))->find($proposalId);
        self::assertNotNull($proposal);

        $submit = $this->request('tools/call', ['id' => $id + 1, 'params' => ['name' => 'nhk.proposal.submit', 'arguments' => ['id' => $proposalId]]], ['Mcp-Name' => 'nhk.proposal.submit']);
        self::assertSame(200, $submit->get_status(), (string) wp_json_encode($submit->get_data()));
        $approve = $this->request('tools/call', ['id' => $id + 2, 'params' => ['name' => 'nhk.proposal.approve', 'arguments' => ['id' => $proposalId, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint]]], ['Mcp-Name' => 'nhk.proposal.approve']);
        self::assertSame(200, $approve->get_status(), (string) wp_json_encode($approve->get_data()));
        $apply = $this->request('tools/call', ['id' => $id + 3, 'params' => ['name' => 'nhk.proposal.apply', 'arguments' => ['id' => $proposalId]]], ['Mcp-Name' => 'nhk.proposal.apply']);
        self::assertSame(200, $apply->get_status(), (string) wp_json_encode($apply->get_data()));
        self::assertFalse($apply->get_data()['result']['isError'], (string) wp_json_encode($apply->get_data()));
        return $apply->get_data()['result']['structuredContent'];
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
