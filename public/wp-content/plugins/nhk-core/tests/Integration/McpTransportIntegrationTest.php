<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Governance\GovernanceCapabilities;
use NHK\Core\Application\Mcp\McpAbilityRegistration;
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
        self::assertCount(count(\NHK\Core\Application\Mcp\McpToolCatalog::tools()), $data['result']['tools']);
        self::assertSame(['type' => 'object', 'properties' => ['q' => ['type' => 'string'], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]], 'required' => ['q'], 'additionalProperties' => false], $data['result']['tools'][0]['inputSchema']);
    }

    public function test_mcp_registration_initializes_semantic_context_resolver_and_serves_read_tool(): void
    {
        $response = $this->request('tools/call', [
            'id' => 101,
            'params' => [
                'name' => 'nhk.semantic.resolve',
                'arguments' => ['context' => []],
            ],
        ], ['Mcp-Name' => 'nhk.semantic.resolve']);

        self::assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        self::assertFalse($response->get_data()['result']['isError'] ?? true, (string) wp_json_encode($response->get_data()));
        self::assertSame(['resolved' => [], 'candidates' => [], 'ambiguities' => [], 'missing' => [], 'conflicts' => [], 'relations' => []], $response->get_data()['result']['structuredContent']);
    }

    public function test_wordpress_abilities_register_public_read_and_governed_video_allowlist(): void
    {
        $abilities = wp_get_abilities(['namespace' => 'nhk-v3']);
        self::assertSame(McpAbilityRegistration::abilityNames(), array_values(array_map(static fn (\WP_Ability $ability): string => $ability->get_name(), $abilities)));
        $read = wp_get_ability('nhk-v3/semantic-resolve');
        self::assertNotNull($read);
        self::assertSame('nhk-v3-content-operations', $read->get_category());
        self::assertTrue($read->get_meta_item('public'));
        self::assertTrue($read->get_meta_item('show_in_rest'));
        self::assertSame(['readonly' => true, 'destructive' => false, 'idempotent' => true], $read->get_meta_item('annotations'));
        self::assertNull(wp_get_ability('nhk-v3/media-ingest'));
        foreach (McpAbilityRegistration::governedAbilityNames() as $abilityName) {
            $ability = wp_get_ability($abilityName);
            self::assertNotNull($ability, $abilityName);
            self::assertTrue($ability->get_meta_item('public'));
            self::assertTrue($ability->get_meta_item('show_in_rest'));
            self::assertFalse($ability->get_meta_item('annotations')['readonly']);
        }
        self::assertNotNull(wp_get_ability('nhk-v3/article-preflight'));
        self::assertNotNull(wp_get_ability('nhk-v3/article-ingest'));
        $administrator = get_role('administrator');
        self::assertNotNull($administrator);
        $administrator->add_cap('read');
        $users = get_users(['role' => 'administrator', 'number' => 1]);
        self::assertNotEmpty($users);
        $previousUser = get_current_user_id();
        wp_set_current_user((int) $users[0]->ID);
        try {
            self::assertSame(['resolved' => [], 'candidates' => [], 'ambiguities' => [], 'missing' => [], 'conflicts' => [], 'relations' => []], $read->execute(['context' => []]));
        } finally {
            wp_set_current_user($previousUser);
        }
    }

    public function test_article_preflight_research_for_existing_post_reads_media_usage_inventory(): void
    {
        $post = get_post(71);
        self::assertNotNull($post, 'The governed Article preflight fixture requires WordPress Post 71.');

        $response = $this->request('tools/call', [
            'id' => 71,
            'params' => [
                'name' => 'nhk.article.preflight',
                'arguments' => [
                    'intent' => 'reconcile',
                    'research_topic' => (string) $post->post_title,
                    'target_wp_post' => [
                        'endpoint_type' => 'wp_post',
                        'endpoint_key' => '1:71',
                    ],
                    'research_subject' => [
                        'subjects' => [
                            ['type' => 'variant', 'id' => '95873bfe-d978-4eda-a5a2-ce9ba79625df'],
                            ['type' => 'music', 'id' => '4b01eb30-2b44-4c9c-a000-781bb8cb9206'],
                            ['type' => 'music', 'id' => '1ffade21-4cb8-44b5-ac1f-16de4ee533f6'],
                        ],
                    ],
                ],
            ],
        ], ['Mcp-Name' => 'nhk.article.preflight']);

        self::assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $result = $response->get_data()['result'];
        self::assertFalse($result['isError'] ?? true, (string) wp_json_encode($result));
        $packet = $result['structuredContent'];
        self::assertNotSame('unavailable', $packet['inventory']['status'] ?? null, (string) wp_json_encode($packet));
        self::assertNotContains('RUNTIME_UNAVAILABLE', $packet['blockers'] ?? [], (string) wp_json_encode($packet));
        self::assertNotContains('Call to a member function listByEndpoint() on null', $packet['inventory']['reason'] ?? '', (string) wp_json_encode($packet));
    }

    public function test_tools_call_enforces_required_and_uuid_schema_arguments(): void
    {
        $missing = $this->request('tools/call', ['id' => 22, 'params' => ['name' => 'nhk.entity.get', 'arguments' => ['type' => 'brand']]], ['Mcp-Name' => 'nhk.entity.get']);
        self::assertSame(400, $missing->get_status());
        self::assertSame(-32602, $missing->get_data()['error']['code']);
        $invalid = $this->request('tools/call', ['id' => 23, 'params' => ['name' => 'nhk.entity.get', 'arguments' => ['type' => 'brand', 'id' => '00000000-0000-0000-0000-000000000000']]], ['Mcp-Name' => 'nhk.entity.get']);
        self::assertSame(400, $invalid->get_status());
        self::assertSame(-32602, $invalid->get_data()['error']['code']);
        $empty = $this->request('tools/call', ['id' => 25, 'params' => ['name' => 'nhk.entity.get', 'arguments' => ['type' => '', 'id' => '550E8400-E29B-41D4-A716-446655440000']]], ['Mcp-Name' => 'nhk.entity.get']);
        self::assertSame(400, $empty->get_status());
        self::assertSame(-32602, $empty->get_data()['error']['code']);
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
        self::assertCount(count(\NHK\Core\Application\Mcp\McpToolCatalog::tools()), $response->get_data()['result']['tools']);
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

    public function test_nullable_optional_mcp_uuid_fields_pass_schema_validation_before_capability_check(): void
    {
        $response = $this->request('tools/call', ['id' => 26, 'params' => ['name' => 'nhk.proposal.create', 'arguments' => ['operation' => 'create', 'payload' => [], 'target_uuid' => null]]], ['Mcp-Name' => 'nhk.proposal.create']);
        self::assertSame(403, $response->get_status());
        self::assertSame(-32003, $response->get_data()['error']['code']);
    }

    public function test_rest_proposal_create_normalizes_empty_optional_target_uuid(): void
    {
        $users = get_users(['role' => 'administrator', 'number' => 1]);
        self::assertNotEmpty($users);
        GovernanceCapabilities::register();
        $previousUser = get_current_user_id();
        wp_set_current_user((int) $users[0]->ID);
        global $wpdb;
        $idempotencyKey = 'rest-empty-target-' . bin2hex(random_bytes(4));
        try {
            $request = new \WP_REST_Request('POST', '/nhk/v1/governance/proposals');
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode(['operation' => 'create', 'entity_type' => 'brand', 'target_uuid' => '', 'idempotency_key' => $idempotencyKey, 'payload' => ['stable_key' => $idempotencyKey, 'name' => 'REST empty target']]));
            $response = rest_do_request($request);
            self::assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
            self::assertNull($response->get_data()['target_uuid']);
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_proposals WHERE idempotency_key=%s", $idempotencyKey));
        } finally {
            wp_set_current_user($previousUser);
        }
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

            $invalidStableKey = $this->request('tools/call', ['id' => 51, 'params' => ['name' => 'nhk.media.ingest', 'arguments' => [
                'stable_key' => 'Invalid Stable Key',
                'name' => 'Invalid stable key media',
            ]]], ['Mcp-Name' => 'nhk.media.ingest']);
            self::assertSame(400, $invalidStableKey->get_status());
            self::assertSame(-32602, $invalidStableKey->get_data()['error']['code']);

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
            $read = $this->request('tools/call', ['id' => 52, 'params' => ['name' => 'nhk.media.get', 'arguments' => ['id' => $media->canonicalId]]], ['Mcp-Name' => 'nhk.media.get']);
            self::assertSame(200, $read->get_status());
            self::assertFalse($read->get_data()['result']['isError']);
            self::assertSame('/media/asset/' . $asset->assetId . '/', $read->get_data()['result']['structuredContent']['assets'][0]['public_url']);
            self::assertArrayNotHasKey('storage_key', $read->get_data()['result']['structuredContent']['assets'][0]);
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
            $invalidUrl = $this->request('tools/call', ['id' => 8, 'params' => ['name' => 'nhk.video.ingest', 'arguments' => [
                'url' => 'not-a-uri',
            ]]], ['Mcp-Name' => 'nhk.video.ingest']);
            self::assertSame(400, $invalidUrl->get_status());
            self::assertSame(-32602, $invalidUrl->get_data()['error']['code']);

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
            self::assertTrue($apply->get_data()['result']['isError'], (string) wp_json_encode($apply->get_data()));
            self::assertStringContainsString('NO_SEMANTIC_ATTACHMENT', (string) ($apply->get_data()['result']['content'][0]['text'] ?? ''));
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
                'visibility' => 'PUBLIC',
                'metadata' => ['source' => 'mcp-integration-test'],
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
                'visibility' => 'PUBLIC',
                'metadata' => ['source' => 'mcp-integration-test'],
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
            self::assertTrue($sourceRecord->isPublic());
            self::assertSame($claimRecord->canonicalId, $evidenceRecord->claimId);
            self::assertSame($sourceRecord->canonicalId, $evidenceRecord->sourceId);
            self::assertTrue($evidenceRecord->isPublic());

            $claimRead = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/knowledge/claim/' . $claimRecord->stableKey));
            $sourceRead = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/knowledge/source/' . $sourceRecord->stableKey));
            $evidenceRead = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/knowledge/evidence/' . $evidenceRecord->canonicalId));
            self::assertSame(200, $claimRead->get_status());
            self::assertSame(200, $sourceRead->get_status());
            self::assertSame(404, $evidenceRead->get_status());
            self::assertCount(1, $claimRead->get_data()['evidence']);
            self::assertCount(1, $sourceRead->get_data()['evidence']);
            self::assertArrayNotHasKey('metadata', $sourceRead->get_data());
            self::assertArrayNotHasKey('metadata', $claimRead->get_data()['evidence'][0]);
            self::assertArrayNotHasKey('provenance', $claimRead->get_data());
            $sourceMcp = $this->request('tools/call', ['id' => 60, 'params' => ['name' => 'nhk.source.get', 'arguments' => ['id' => $sourceRecord->canonicalId]]], ['Mcp-Name' => 'nhk.source.get']);
            $evidenceMcp = $this->request('tools/call', ['id' => 61, 'params' => ['name' => 'nhk.evidence.get', 'arguments' => ['id' => $evidenceRecord->canonicalId]]], ['Mcp-Name' => 'nhk.evidence.get']);
            self::assertSame(200, $sourceMcp->get_status());
            self::assertSame(200, $evidenceMcp->get_status());
            self::assertSame($sourceRecord->canonicalId, $sourceMcp->get_data()['result']['structuredContent']['id']);
            self::assertSame($evidenceRecord->canonicalId, $evidenceMcp->get_data()['result']['structuredContent']['id']);
            self::assertArrayNotHasKey('metadata', $evidenceMcp->get_data()['result']['structuredContent']);

            $conflict = $this->request('tools/call', ['id' => 62, 'params' => ['name' => 'nhk.source.ingest', 'arguments' => [
                'stable_key' => 'mcp-source-conflict-' . $suffix,
                'title' => 'Conflicting visibility',
                'visibility' => 'PUBLIC',
                'metadata' => ['visibility' => 'PRIVATE'],
            ]]], ['Mcp-Name' => 'nhk.source.ingest']);
            self::assertSame(400, $conflict->get_status());
            self::assertSame(-32602, $conflict->get_data()['error']['code']);
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
