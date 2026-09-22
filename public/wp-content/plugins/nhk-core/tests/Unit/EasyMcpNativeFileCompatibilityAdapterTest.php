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
        self::assertSame(['download_url', 'file_id'], $capture['inputSchema']['properties']['files']['items']['required']);
        $relation = $capture['inputSchema']['properties']['authority_intent']['properties']['relation_intents'];
        self::assertSame(['source_type', 'source_uuid', 'predicate', 'target_type', 'target_uuid', 'provenance', 'reason'], array_keys($relation['items']['properties']));
        self::assertSame(['source_type', 'source_uuid', 'predicate', 'target_type', 'target_uuid'], $relation['items']['required']);
        self::assertFalse($relation['items']['additionalProperties']);
        $structured = $capture['inputSchema']['properties']['authority_intent']['properties']['relations'];
        self::assertSame(['source_uuid', 'predicate', 'target_uuid'], array_keys($structured['items']['properties']));
        self::assertSame(['source_uuid', 'predicate', 'target_uuid'], $structured['items']['required']);
        self::assertFalse($structured['items']['additionalProperties']);
    }

    public function test_r2_relationship_endpoint_schema_survives_catalog_ability_and_final_tools_list(): void
    {
        $catalog = array_column(McpToolCatalog::tools(), null, 'name');
        $catalogSchema = $catalog['nhk.capture.ingest']['inputSchema'];
        $catalogEndpoint = $catalogSchema['properties']['relationship_operations']['items']['properties']['source'];

        $abilitySchema = McpAbilityRegistration::inputSchemaForTool('nhk.capture.ingest');
        $abilityEndpoint = $abilitySchema['properties']['relationship_operations']['items']['properties']['source'];

        $projected = EasyMcpNativeFileCompatibilityAdapter::projectTools([[
            'name' => self::TARGET,
            'inputSchema' => $abilitySchema,
        ]])[0];
        $connectorEndpoint = $projected['inputSchema']['properties']['relationship_operations']['items']['properties']['source'];

        $request = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp/'; }
            public function get_json_params(): array { return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']; }
        };
        $final = EasyMcpNativeFileCompatibilityAdapter::projectFinalToolsListDescriptor([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['tools' => [$projected]],
        ], null, $request);
        $finalEndpoint = $final['result']['tools'][0]['inputSchema']['properties']['relationship_operations']['items']['properties']['source'];

        foreach ([$catalogEndpoint, $abilityEndpoint, $connectorEndpoint, $finalEndpoint] as $endpoint) {
            self::assertSame('object', $endpoint['type']);
            self::assertSame(['type', 'id'], array_keys($endpoint['properties']));
            self::assertSame('string', $endpoint['properties']['type']['type']);
            self::assertSame('string', $endpoint['properties']['id']['type']);
            self::assertSame('uuid', $endpoint['properties']['id']['format']);
            self::assertSame(['type', 'id'], $endpoint['required']);
            self::assertFalse($endpoint['additionalProperties']);
        }
        self::assertSame($catalogEndpoint, $abilityEndpoint);
        self::assertSame($catalogEndpoint, $connectorEndpoint);
        self::assertSame($catalogEndpoint, $finalEndpoint);

        self::assertStrictConnectorValueAccepted(
            ['type' => 'Video', 'id' => '11111111-1111-4111-8111-111111111111'],
            $connectorEndpoint,
        );
    }

    public function test_projection_does_not_change_unrelated_tools(): void
    {
        $tool = ['name' => 'wp_ability_nhk_v3_media_ingest', 'inputSchema' => ['type' => 'object']];
        $projected = EasyMcpNativeFileCompatibilityAdapter::projectTools([$tool])[0];
        $catalog = array_column(McpToolCatalog::tools(), null, 'name');
        self::assertJsonStringEqualsJsonString(
            json_encode(EasyMcpNativeFileCompatibilityAdapter::normalizeFinalInputSchema($catalog['nhk.media.ingest']['inputSchema']), JSON_THROW_ON_ERROR),
            json_encode($projected['inputSchema'], JSON_THROW_ON_ERROR),
        );
    }

    public function test_projection_removes_invalid_empty_list_metadata_from_unrelated_tools(): void
    {
        $tool = [
            'name' => 'wp_ability_nhk_v3_media_ingest',
            'inputSchema' => ['type' => 'object'],
            '_meta' => [],
        ];

        $projected = EasyMcpNativeFileCompatibilityAdapter::projectTools([
            $tool,
            ['name' => self::TARGET, 'inputSchema' => ['type' => 'object']],
        ]);

        self::assertSame(McpToolCatalog::schemaHash('nhk.media.ingest'), $projected[0]['_meta']['nhk/schemaHash']);
    }

    public function test_arbitrary_no_argument_tool_uses_strict_json_object_schema(): void
    {
        $projected = EasyMcpNativeFileCompatibilityAdapter::projectTools([[
            'name' => 'wp_ability_nhk_v3_arbitrary_no_argument',
            'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
        ]])[0];

        $encoded = json_encode($projected['inputSchema'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertSame('{"type":"object","properties":{}}', $encoded);
    }

    public function test_all_registered_nhk_connector_schemas_have_valid_json_schema_shapes(): void
    {
        $catalog = array_column(McpToolCatalog::tools(), null, 'name');
        $tools = [];
        foreach ($catalog as $toolName => $tool) {
            $ability = McpAbilityRegistration::abilityNameForTool($toolName);
            if ($ability === null) continue;
            $tools[] = [
                'name' => McpAbilityRegistration::connectorToolNameForAbility($ability),
                'inputSchema' => $tool['inputSchema'],
            ];
        }

        foreach (EasyMcpNativeFileCompatibilityAdapter::projectTools($tools) as $tool) {
            $schema = json_decode(json_encode($tool['inputSchema'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            self::assertSame('object', $schema->type ?? null, (string) $tool['name']);
            self::assertIsObject($schema->properties ?? null, (string) $tool['name']);
            self::assertFalse(isset($schema->required) && $schema->required === [], (string) $tool['name']);
            self::assertValidJsonSchemaNode($schema, (string) $tool['name']);
        }
    }

    public function test_open_widget_descriptor_projects_mcp_apps_resource_metadata(): void
    {
        $tools = [[
            'name' => 'wp_ability_nhk_v3_media_upload_widget_open',
            'description' => 'stale Easy MCP description',
            'inputSchema' => ['type' => 'object', 'properties' => []],
            'annotations' => ['title' => 'Widget'],
            '_meta' => [
                'securitySchemes' => [['type' => 'oauth2', 'scopes' => ['media:write']]],
                'auth' => ['required' => true],
            ],
        ]];

        $projected = EasyMcpNativeFileCompatibilityAdapter::projectTools($tools);

        self::assertSame('ui://nhk/image-upload/v2.html', $projected[0]['_meta']['ui']['resourceUri']);
        self::assertSame(['model', 'app'], $projected[0]['_meta']['ui']['visibility']);
        self::assertSame('ui://nhk/image-upload/v2.html', $projected[0]['_meta']['openai/outputTemplate']);
        self::assertSame([['type' => 'oauth2', 'scopes' => ['media:write']]], $projected[0]['_meta']['securitySchemes']);
        self::assertSame(['required' => true], $projected[0]['_meta']['auth']);
    }

    public function test_widget_upload_descriptor_restores_catalog_schema_without_changing_runtime_name(): void
    {
        $tools = [[
            'name' => 'wp_ability_nhk_v3_media_widget_upload',
            'description' => 'stale Easy MCP description',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'idempotency_key' => ['type' => 'string'],
                'files' => ['type' => 'array'],
            ], 'required' => ['idempotency_key', 'files']],
            'annotations' => ['title' => 'Widget upload'],
        ]];

        $projected = EasyMcpNativeFileCompatibilityAdapter::projectTools($tools);
        $widget = $projected[0];

        self::assertSame('wp_ability_nhk_v3_media_widget_upload', $widget['name']);
        self::assertSame(['idempotency_key', 'files'], $widget['inputSchema']['required']);
        self::assertArrayNotHasKey('required', $widget['inputSchema']['properties']['metadata']);
        self::assertArrayHasKey('items', $widget['inputSchema']['properties']);
        self::assertArrayNotHasKey('_meta', $widget);
        self::assertSame(['download_url', 'file_id'], $widget['inputSchema']['properties']['files']['items']['required']);
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
        self::assertSame(['VIDEO', 'IMAGE_ARTICLE', 'TEXT_ARTICLE', 'KNOWLEDGE_DELTA', 'KNOWLEDGE_REPAIR', 'MEDIA_ENRICHMENT'], $tools[self::TARGET]['inputSchema']['properties']['intent']['enum']);
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
        self::assertSame(['download_url', 'file_id'], $tools[self::TARGET]['inputSchema']['properties']['files']['items']['required']);
        self::assertSame(['files'], $tools[self::TARGET]['_meta']['openai/fileParams']);
        self::assertArrayHasKey('relation_intents', $tools[self::TARGET]['inputSchema']['properties']['authority_intent']['properties']);
        self::assertSame(
            ['source_type', 'source_uuid', 'predicate', 'target_type', 'target_uuid', 'provenance', 'reason'],
            array_keys($tools[self::TARGET]['inputSchema']['properties']['authority_intent']['properties']['relation_intents']['items']['properties']),
        );
        self::assertJsonStringEqualsJsonString(
            json_encode(EasyMcpNativeFileCompatibilityAdapter::normalizeFinalInputSchema($catalog['nhk.media.ingest']['inputSchema']), JSON_THROW_ON_ERROR),
            json_encode($tools['wp_ability_nhk_v3_media_ingest']['inputSchema'], JSON_THROW_ON_ERROR),
        );
        self::assertSame(McpToolCatalog::schemaHash('nhk.media.ingest'), $tools['wp_ability_nhk_v3_media_ingest']['_meta']['nhk/schemaHash']);
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

    public function test_tools_list_normalizes_empty_metadata_without_nhk_projection_target(): void
    {
        $request = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp'; }
            public function get_json_params(): array { return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']; }
        };
        $response = new class {
            /** @var array<string,mixed> */
            private array $data = ['result' => ['tools' => [[
                'name' => 'wp_ability_core_posts_list',
                'inputSchema' => ['type' => 'object'],
                '_meta' => [],
            ]]]];

            /** @return array<string,mixed> */
            public function get_data(): array { return $this->data; }

            /** @param array<string,mixed> $data */
            public function set_data(array $data): void { $this->data = $data; }
        };

        $projected = EasyMcpNativeFileCompatibilityAdapter::projectToolsListDescriptor($response, null, $request);
        self::assertArrayNotHasKey('_meta', $projected->get_data()['result']['tools'][0]);
    }

    public function test_final_easy_mcp_descriptor_links_open_widget_to_registered_resource(): void
    {
        $request = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp'; }
        };
        $response = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['tools' => [[
                'name' => 'wp_ability_nhk_v3_media_upload_widget_open',
                'description' => 'stale Easy MCP description',
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'annotations' => ['title' => 'Widget'],
                '_meta' => [
                    'securitySchemes' => [['type' => 'oauth2', 'scopes' => ['media:write']]],
                    'auth' => ['required' => true],
                ],
            ]]],
        ];

        $final = EasyMcpNativeFileCompatibilityAdapter::projectFinalToolsListDescriptor($response, null, $request);
        $widget = $final['result']['tools'][0];

        self::assertSame('ui://nhk/image-upload/v2.html', $widget['_meta']['ui']['resourceUri']);
        self::assertSame(['model', 'app'], $widget['_meta']['ui']['visibility']);
        self::assertSame('ui://nhk/image-upload/v2.html', $widget['_meta']['openai/outputTemplate']);
        self::assertSame([['type' => 'oauth2', 'scopes' => ['media:write']]], $widget['_meta']['securitySchemes']);
        self::assertSame(['required' => true], $widget['_meta']['auth']);
    }

    public function test_final_serialized_open_widget_descriptor_uses_valid_minimal_no_argument_schema(): void
    {
        $request = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp'; }
            public function get_json_params(): array { return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']; }
        };
        $response = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['tools' => [[
                'name' => 'wp_ability_nhk_v3_media_upload_widget_open',
                'description' => 'Easy MCP serialized descriptor',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'annotations' => ['title' => 'NHK Image Upload Widget'],
            ]]],
        ];

        $final = EasyMcpNativeFileCompatibilityAdapter::projectFinalToolsListDescriptor($response, null, $request);
        $encoded = json_encode($final['result']['tools'][0], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $descriptor = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            '{"type":"object","properties":{}}',
            json_encode($descriptor->inputSchema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        self::assertSame('ui://nhk/image-upload/v2.html', $descriptor->_meta->ui->resourceUri);
        self::assertSame('ui://nhk/image-upload/v2.html', $descriptor->_meta->{'openai/outputTemplate'});
    }

    public function test_easy_mcp_resources_list_projects_the_nhk_widget_resource(): void
    {
        $request = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp'; }
            public function get_json_params(): array { return ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list', 'params' => []]; }
        };
        $response = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['resources' => []],
        ];

        $final = EasyMcpNativeFileCompatibilityAdapter::projectFinalToolsListDescriptor($response, null, $request);
        $resources = array_column($final['result']['resources'], null, 'uri');

        self::assertSame('text/html;profile=mcp-app', $resources['ui://nhk/image-upload/v2.html']['mimeType']);
        self::assertArrayHasKey('ui://nhk/image-upload/v2.html', $resources);
    }

    public function test_easy_mcp_resources_read_projects_the_nhk_widget_html(): void
    {
        $request = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp'; }
            public function get_json_params(): array { return ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/read', 'params' => ['uri' => 'ui://nhk/image-upload/v2.html']]; }
        };
        $response = [
            'jsonrpc' => '2.0',
            'id' => 3,
            'error' => ['code' => -32004, 'message' => 'Resource not found'],
        ];

        $final = EasyMcpNativeFileCompatibilityAdapter::projectFinalToolsListDescriptor($response, null, $request);

        self::assertSame('ui://nhk/image-upload/v2.html', $final['result']['contents'][0]['uri']);
        self::assertSame('text/html;profile=mcp-app', $final['result']['contents'][0]['mimeType']);
        self::assertNotEmpty($final['result']['contents'][0]['text']);
        self::assertStringContainsString('<input', $final['result']['contents'][0]['text']);
    }

    public function test_easy_mcp_1718_modern_tools_list_keeps_widget_metadata_and_server_envelope(): void
    {
        $request = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp'; }
            public function get_json_params(): array { return [
                'jsonrpc' => '2.0',
                'id' => 11,
                'method' => 'tools/list',
                'params' => ['_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ]],
            ]; }
        };
        $response = [
            'jsonrpc' => '2.0',
            'id' => 11,
            'result' => [
                'tools' => [[
                    'name' => 'wp_ability_nhk_v3_media_upload_widget_open',
                    'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                    '_meta' => ['auth' => ['required' => true]],
                ]],
                'resultType' => 'complete',
                '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'easy-mcp-ai', 'version' => '1.7.18']],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
        ];

        $final = EasyMcpNativeFileCompatibilityAdapter::projectFinalToolsListDescriptor($response, null, $request);
        $widget = $final['result']['tools'][0];

        self::assertSame('ui://nhk/image-upload/v2.html', $widget['_meta']['ui']['resourceUri']);
        self::assertSame(['model', 'app'], $widget['_meta']['ui']['visibility']);
        self::assertSame('ui://nhk/image-upload/v2.html', $widget['_meta']['openai/outputTemplate']);
        self::assertSame(['required' => true], $widget['_meta']['auth']);
        self::assertSame('1.7.18', $final['result']['_meta']['io.modelcontextprotocol/serverInfo']['version']);
    }

    public function test_easy_mcp_1718_modern_resources_read_projects_exact_uri_and_keeps_unknown_uri_failed_closed(): void
    {
        $request = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp'; }
            public function get_json_params(): array { return [
                'jsonrpc' => '2.0',
                'id' => 12,
                'method' => 'resources/read',
                'params' => [
                    'uri' => 'ui://nhk/image-upload/v2.html',
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ]; }
        };
        $response = [
            'jsonrpc' => '2.0',
            'id' => 12,
            'result' => [
                'contents' => [],
                'resultType' => 'complete',
                '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'easy-mcp-ai', 'version' => '1.7.18']],
            ],
        ];

        $final = EasyMcpNativeFileCompatibilityAdapter::projectFinalToolsListDescriptor($response, null, $request);
        self::assertSame('ui://nhk/image-upload/v2.html', $final['result']['contents'][0]['uri']);
        self::assertSame('text/html;profile=mcp-app', $final['result']['contents'][0]['mimeType']);
        self::assertNotEmpty($final['result']['contents'][0]['text']);
        self::assertArrayNotHasKey('error', $final);

        $unknownRequest = new class {
            public function get_route(): string { return '/easy-mcp-ai/v1/mcp'; }
            public function get_json_params(): array { return [
                'jsonrpc' => '2.0', 'id' => 13, 'method' => 'resources/read',
                'params' => ['uri' => 'ui://nhk/image-upload/unknown.html'],
            ]; }
        };
        $unknown = ['jsonrpc' => '2.0', 'id' => 13, 'error' => ['code' => -32602, 'message' => 'Invalid params']];
        self::assertSame($unknown, EasyMcpNativeFileCompatibilityAdapter::projectFinalToolsListDescriptor($unknown, null, $unknownRequest));
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
        self::assertTrue(EasyMcpNativeFileCompatibilityAdapter::isSupportedVersion('1.7.18'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::isSupportedVersion('2.0.0'));
    }

    private static function assertValidJsonSchemaNode(mixed $schema, string $toolName): void
    {
        if ($schema instanceof \stdClass) {
            $type = $schema->type ?? null;
            if ($type === 'object') {
                self::assertIsObject($schema->properties ?? null, $toolName . '.properties');
                self::assertFalse(isset($schema->required) && $schema->required === [], $toolName . '.required');
                foreach (get_object_vars($schema->properties ?? new \stdClass()) as $child) self::assertValidJsonSchemaNode($child, $toolName);
            }
            if ($type === 'array') self::assertTrue(property_exists($schema, 'items'), $toolName . '.items');
            foreach (['items', 'additionalProperties', 'contains', 'propertyNames', 'not'] as $key) {
                if (property_exists($schema, $key)) self::assertValidJsonSchemaNode($schema->{$key}, $toolName . '.' . $key);
            }
            foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $key) {
                if (!property_exists($schema, $key) || !is_array($schema->{$key})) continue;
                foreach ($schema->{$key} as $child) self::assertValidJsonSchemaNode($child, $toolName . '.' . $key);
            }
            foreach (['$defs', 'definitions', 'patternProperties'] as $key) {
                if (!property_exists($schema, $key)) continue;
                self::assertIsObject($schema->{$key}, $toolName . '.' . $key);
                foreach (get_object_vars($schema->{$key}) as $child) self::assertValidJsonSchemaNode($child, $toolName . '.' . $key);
            }
        }
        if (is_array($schema)) {
            foreach ($schema as $child) self::assertValidJsonSchemaNode($child, $toolName);
        }
    }

    /** @param array<string,mixed> $value @param array<string,mixed> $schema */
    private static function assertStrictConnectorValueAccepted(array $value, array $schema): void
    {
        self::assertSame('object', $schema['type']);
        foreach ($schema['required'] as $property) self::assertArrayHasKey($property, $value);
        if (($schema['additionalProperties'] ?? true) === false) {
            self::assertSame([], array_diff(array_keys($value), array_keys($schema['properties'])));
        }
        foreach ($schema['properties'] as $property => $propertySchema) {
            if (!array_key_exists($property, $value)) continue;
            self::assertSame($propertySchema['type'], is_string($value[$property]) ? 'string' : gettype($value[$property]));
            if (($propertySchema['format'] ?? null) === 'uuid') {
                self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value[$property]);
            }
        }
    }

    public function test_version_diagnostic_is_explicit_and_fail_closed(): void
    {
        self::assertSame([
            'code' => 'EASY_MCP_NATIVE_FILE_COMPAT_ACTIVE',
            'version' => '1.7.16',
            'native_file_support_upstream' => false,
        ], EasyMcpNativeFileCompatibilityAdapter::diagnosticForVersion('1.7.16'));
        self::assertSame('EASY_MCP_NATIVE_FILE_COMPAT_ACTIVE', EasyMcpNativeFileCompatibilityAdapter::diagnosticForVersion('1.7.18')['code']);
    }

    public function test_multipart_scope_requires_exact_endpoint_target_and_native_file_parts(): void
    {
        $rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => self::TARGET, 'arguments' => ['text' => 'x']]];
        $files = ['files' => ['name' => ['one.jpg'], 'tmp_name' => ['/tmp/native.jpg'], 'type' => ['image/jpeg'], 'size' => [12], 'error' => [0]]];

        self::assertTrue(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, $files, '1.7.16'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/nhk/v1/mcp', $rpc, $files, '1.7.16'));
        self::assertFalse(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, [], '1.7.16'));
        self::assertTrue(EasyMcpNativeFileCompatibilityAdapter::shouldHandle('/easy-mcp-ai/v1/mcp', $rpc, $files, '1.7.18'));

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
        $seen = $method->invoke(null, $files, static function (): array {
            return $_FILES;
        });

        self::assertSame($files, $seen);
        self::assertSame($previous, $_FILES ?? []);
    }
}
