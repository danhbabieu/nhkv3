<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Media\ImageIngestEntrypoint;
use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpReadHandler, McpToolCatalog, McpTransport};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Infrastructure\Mcp\ChatGptMcpGatewayException;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class McpWidgetUploadTest extends TestCase
{
    public function test_widget_upload_tool_declares_structured_openai_file_references(): void
    {
        $tool = $this->tool('nhk.media.widget-upload');

        self::assertSame('mutation', $tool['kind']);
        self::assertSame(['idempotency_key', 'files'], $tool['inputSchema']['required']);
        self::assertArrayNotHasKey('required', $tool['inputSchema']['properties']['metadata']);
        self::assertSame(1, $tool['inputSchema']['properties']['files']['minItems']);
        self::assertSame(20, $tool['inputSchema']['properties']['files']['maxItems']);
        self::assertSame(['download_url', 'file_id'], $tool['inputSchema']['properties']['files']['items']['required']);
        self::assertArrayHasKey('ordinal', $tool['inputSchema']['properties']['files']['items']['properties']);
        self::assertArrayHasKey('items', $tool['inputSchema']['properties']);
        self::assertArrayHasKey('ordinal', $tool['inputSchema']['properties']['items']['items']['properties']);
        self::assertFalse($tool['inputSchema']['properties']['files']['items']['additionalProperties']);
        self::assertArrayNotHasKey('connectorMeta', $tool);
        $open = $this->tool('nhk.media.upload-widget.open');
        self::assertSame('ui://nhk/image-upload/v2.html', $open['connectorMeta']['ui']['resourceUri'] ?? null);
        self::assertSame(['model', 'app'], $open['connectorMeta']['ui']['visibility'] ?? null);
    }

    public function test_widget_upload_delegates_one_structured_reference_and_returns_file_id(): void
    {
        $calls = [];
        $materializerCalls = 0;
        $transport = $this->transport($calls, $materializerCalls);
        $result = $this->call($transport, [
            'idempotency_key' => 'widget-one',
            'metadata' => ['description' => 'Mặt trước đồng hồ Odo 36/10'],
            'files' => [['download_url' => 'https://files.openai.test/one', 'file_id' => 'file_one', 'file_name' => 'one.jpg']],
        ]);

        self::assertSame(['file_one'], array_column($result['uploads'], 'file_id'));
        self::assertSame(1, $result['requested_count']);
        self::assertSame(1, $result['success_count']);
        self::assertSame(0, $result['failure_count']);
        self::assertSame(0, $result['items'][0]['ordinal']);
        self::assertSame('success', $result['items'][0]['status']);
        self::assertSame('/anh/safe-1.webp', $result['uploads'][0]['canonical_url']);
        self::assertSame('verified', $result['uploads'][0]['attachment_readback_status']);
        self::assertNotEmpty($result['batch_id']);
        self::assertSame('Mặt trước đồng hồ Odo 36/10', $result['user_context']);
        self::assertSame(['media-1'], $result['ordered_media_ids']);
        self::assertSame('COMPLETE', $result['media_commit_status']);
        self::assertSame('NOT_RUN', $result['enrichment_status']);
        self::assertSame([['widget-one', ['source' => 'chatgpt_widget', 'description' => 'Mặt trước đồng hồ Odo 36/10'], 'file_one']], $calls);
        self::assertSame(1, $materializerCalls);
    }

    public function test_widget_upload_preserves_multi_file_order_and_cardinality(): void
    {
        $calls = [];
        $materializerCalls = 0;
        $transport = $this->transport($calls, $materializerCalls);
        $result = $this->call($transport, [
            'idempotency_key' => 'widget-many',
            'metadata' => ['description' => 'Bộ máy Odo 24 — mặt trước'],
            'files' => [
                ['download_url' => 'https://files.openai.test/a', 'file_id' => 'file_a', 'file_name' => 'a.jpg'],
                ['download_url' => 'https://files.openai.test/b', 'file_id' => 'file_b', 'file_name' => 'b.jpg'],
            ],
        ]);

        self::assertSame(['file_a', 'file_b'], array_column($result['uploads'], 'file_id'));
        self::assertCount(2, $result['uploads']);
        self::assertSame([0, 1], array_column($result['items'], 'ordinal'));
        self::assertSame(['success', 'success'], array_column($result['items'], 'status'));
        self::assertCount(2, $result['ordered_media_ids']);
        self::assertSame('COMPLETE', $result['media_commit_status']);
        self::assertSame(1, $materializerCalls);
    }

    public function test_widget_upload_maps_per_item_metadata_by_stable_ordinal_without_batch_fanout(): void
    {
        $calls = [];
        $materializerCalls = 0;
        $entrypoint = new ImageIngestEntrypoint(
            static function (string $key, array $metadata, array $files, array $items) use (&$calls): array {
                $calls[] = [$metadata, $items];
                return ['batch_id' => 'batch-ordered-metadata', 'items' => array_map(static function (array $item, int $index): array {
                    return [
                        'client_file_id' => $item['client_file_id'],
                        'ordinal' => $index,
                        'attachment_id' => 100 + $index,
                        'media_id' => 'media-' . ($index + 1),
                        'filename' => 'safe-' . ($index + 1) . '.webp',
                        'original_filename' => $item['filename'],
                        'mime_type' => 'image/webp',
                        'width' => 10,
                        'height' => 10,
                        'byte_size' => 100,
                        'source_url' => '/anh/safe-' . ($index + 1) . '.webp',
                        'attachment_readback_status' => 'verified',
                        'media_context' => $item['media'],
                    ];
                }, $items, array_keys($items))];
            },
            static function () use (&$materializerCalls): array {
                $materializerCalls++;
                return ['files' => ['files' => ['name' => ['a.jpg', 'b.jpg'], 'type' => ['image/jpeg', 'image/jpeg'], 'tmp_name' => ['/tmp/a', '/tmp/b'], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK], 'size' => [100, 100]]], 'temporary_paths' => []];
            },
        );
        $transport = new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => true, imageIngest: $entrypoint);
        $result = $this->call($transport, [
            'idempotency_key' => 'widget-ordered-metadata',
            'metadata' => ['description' => 'Bộ ảnh của một hiện vật'],
            'files' => [
                ['download_url' => 'https://files.openai.test/a', 'file_id' => 'file_a', 'file_name' => 'a.jpg', 'ordinal' => 0],
                ['download_url' => 'https://files.openai.test/b', 'file_id' => 'file_b', 'file_name' => 'b.jpg', 'ordinal' => 1],
            ],
            'items' => [
                ['client_file_id' => 'file_a', 'ordinal' => 0, 'media' => ['title' => 'Mặt trước', 'alt_text' => 'Alt trước', 'caption' => 'Caption trước', 'description' => 'Mô tả trước']],
                ['client_file_id' => 'file_b', 'ordinal' => 1, 'media' => ['title' => 'Mặt sau', 'alt_text' => 'Alt sau', 'caption' => 'Caption sau', 'description' => 'Mô tả sau']],
            ],
        ]);

        self::assertSame([0, 1], array_column($result['items'], 'ordinal'));
        self::assertSame(['Mặt trước', 'Mặt sau'], array_column(array_column($result['items'], 'metadata'), 'title'));
        self::assertSame(['Alt trước', 'Alt sau'], array_column(array_column($result['items'], 'metadata'), 'alt_text'));
        self::assertSame(['Caption trước', 'Caption sau'], array_column(array_column($result['items'], 'metadata'), 'caption'));
        self::assertSame(['Mô tả trước', 'Mô tả sau'], array_column(array_column($result['items'], 'metadata'), 'description'));
        self::assertSame('Bộ ảnh của một hiện vật', $result['user_context']);
        self::assertSame('Bộ ảnh của một hiện vật', $calls[0][0]['description']);
        self::assertNotSame($result['user_context'], $result['items'][0]['metadata']['title']);
        self::assertSame(1, $materializerCalls);
    }

    public function test_widget_upload_returns_a_safe_partial_manifest_in_request_order(): void
    {
        $calls = [];
        $materializerCalls = 0;
        $transport = $this->transport($calls, $materializerCalls);
        $result = $this->call($transport, [
            'idempotency_key' => 'widget-partial',
            'metadata' => ['description' => 'Một ảnh lỗi kết nối'],
            'files' => [
                ['download_url' => 'https://files.openai.test/a', 'file_id' => 'file_a', 'file_name' => 'a.jpg'],
                ['download_url' => 'https://files.openai.test/b', 'file_id' => 'file_b', 'file_name' => 'b.jpg'],
            ],
        ]);

        self::assertSame(2, $result['requested_count']);
        self::assertSame(1, $result['success_count']);
        self::assertSame(1, $result['failure_count']);
        self::assertSame([0, 1], array_column($result['items'], 'ordinal'));
        self::assertSame(['success', 'error'], array_column($result['items'], 'status'));
        self::assertSame(['media-1'], $result['ordered_media_ids']);
        self::assertSame('PARTIAL', $result['media_commit_status']);
        self::assertSame('NOT_RUN', $result['enrichment_status']);
        self::assertArrayNotHasKey('download_url', $result['items'][1]);
    }

    public function test_widget_upload_does_not_promote_an_explicit_failed_item_to_success(): void
    {
        $calls = [];
        $materializerCalls = 0;
        $entrypoint = new ImageIngestEntrypoint(
            static function (string $key, array $metadata, array $files, array $items) use (&$calls): array {
                $calls[] = $items;
                return [
                    'items' => [
                        ['ordinal' => 0, 'status' => 'success', 'client_file_id' => 'file_a', 'attachment_id' => 10, 'media_id' => 'media-a', 'filename' => 'a.webp', 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'byte_size' => 100, 'source_url' => '/anh/a.webp', 'attachment_readback_status' => 'verified'],
                        ['ordinal' => 1, 'status' => 'error', 'client_file_id' => 'file_b', 'error' => ['code' => 'PROVIDED_FILE_HTTP_STATUS', 'stage' => 'download']],
                    ],
                ];
            },
            static function (mixed $references) use (&$materializerCalls): array {
                $materializerCalls++;
                return ['files' => ['files' => ['name' => ['a.jpg', 'b.jpg'], 'type' => ['image/jpeg', 'image/jpeg'], 'tmp_name' => ['/tmp/a', '/tmp/b'], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK], 'size' => [100, 100]]], 'temporary_paths' => []];
            },
        );
        $transport = new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => true, imageIngest: $entrypoint);
        $result = $this->call($transport, [
            'idempotency_key' => 'widget-explicit-failure',
            'metadata' => ['description' => 'Explicit failed item'],
            'files' => [
                ['download_url' => 'https://files.openai.test/a', 'file_id' => 'file_a', 'file_name' => 'a.jpg'],
                ['download_url' => 'https://files.openai.test/b', 'file_id' => 'file_b', 'file_name' => 'b.jpg'],
            ],
        ]);

        self::assertSame('partial_success', $result['status']);
        self::assertSame(1, $result['success_count']);
        self::assertSame(1, $result['failure_count']);
        self::assertSame(['success', 'error'], array_column($result['items'], 'status'));
        self::assertSame('PROVIDED_FILE_HTTP_STATUS', $result['items'][1]['error']['code']);
    }

    public function test_widget_upload_fails_closed_without_trustworthy_naming_context(): void
    {
        $calls = [];
        $materializerCalls = 0;
        $transport = $this->transport($calls, $materializerCalls);
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.media.widget-upload', 'arguments' => [
            'idempotency_key' => 'widget-no-context',
            'files' => [['download_url' => 'https://files.openai.test/one', 'file_id' => 'file_one', 'file_name' => 'IMG_0001.jpg']],
        ]]]);

        self::assertSame(400, $response['status']);
        self::assertSame(-32602, $response['body']['error']['code']);
    }

    public function test_widget_upload_does_not_echo_chatgpt_transport_uri_into_model_visible_result(): void
    {
        $calls = [];
        $materializerCalls = 0;
        $transport = $this->transport($calls, $materializerCalls);
        $result = $this->call($transport, [
            'idempotency_key' => 'widget-sediment-result',
            'metadata' => ['description' => 'Đồng hồ cổ'],
            'files' => [[
                'download_url' => 'https://oaiusercontent.example/raw/image',
                'file_id' => 'sediment://file_000000008f2081f5bc1831c3f65480f7',
                'file_name' => 'IMG_0001.jpg',
            ]],
        ]);

        self::assertArrayNotHasKey('file_id', $result['uploads'][0]);
        self::assertSame(1, $materializerCalls);
    }

    public function test_widget_upload_uses_ordinal_fallback_when_ingest_strips_transport_ids(): void
    {
        $calls = [];
        $materializerCalls = 0;
        $transport = $this->transport($calls, $materializerCalls);
        $result = $this->call($transport, [
            'idempotency_key' => 'widget-ordinal',
            'metadata' => ['description' => 'Ordinal mapping'],
            'files' => [
                ['download_url' => 'https://files.openai.test/a', 'file_id' => 'sediment://a', 'file_name' => 'a.jpg'],
                ['download_url' => 'https://files.openai.test/b', 'file_id' => 'sediment://b', 'file_name' => 'b.jpg'],
            ],
        ]);

        self::assertSame([0, 1], array_column($result['items'], 'ordinal'));
        self::assertSame(['media-1', 'media-2'], array_column($result['items'], 'media_id'));
        self::assertSame(2, $result['success_count']);
        self::assertSame(0, $result['failure_count']);
    }

    public function test_widget_upload_returns_typed_materialization_failure_without_creating_attachment_or_media(): void
    {
        $uploadCalls = 0;
        $entrypoint = new ImageIngestEntrypoint(
            static function () use (&$uploadCalls): array {
                $uploadCalls++;
                return [];
            },
            static function (): array {
                throw new ChatGptMcpGatewayException(
                    'PROVIDED_FILE_REFERENCE_UNRESOLVABLE',
                    'The provided file server could not be reached securely.',
                    'files.openai.test',
                    ['typed_code' => 'PROVIDED_FILE_CONNECT_FAILED', 'stage' => 'connect', 'http_status' => 502, 'redirect_count' => 0],
                );
            },
        );
        $transport = new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => true, imageIngest: $entrypoint);
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.media.widget-upload', 'arguments' => [
            'idempotency_key' => 'widget-failed-materialization',
            'metadata' => ['description' => 'Failure must stay typed'],
            'files' => [['download_url' => 'https://files.openai.test/one?sig=secret', 'file_id' => 'file-one', 'file_name' => 'one.jpg']],
        ]]]);

        $result = $response['body']['result']['structuredContent'];
        self::assertSame('error', $result['status']);
        self::assertSame(1, $result['requested_count']);
        self::assertSame(0, $result['success_count']);
        self::assertSame(1, $result['failure_count']);
        self::assertSame('PROVIDED_FILE_CONNECT_FAILED', $result['items'][0]['error']['code']);
        self::assertSame('connect', $result['items'][0]['error']['stage']);
        self::assertArrayNotHasKey('download_url', $result['items'][0]);
        self::assertStringNotContainsString('secret', json_encode($result));
        self::assertSame(0, $uploadCalls, 'No Attachment or Media writer may run after materialization failure.');
    }

    private function transport(array &$calls, int &$materializerCalls): McpTransport
    {
        $entrypoint = new ImageIngestEntrypoint(
            static function (string $key, array $metadata, array $files, array $items) use (&$calls): array {
                $calls[] = [$key, $metadata, ...array_column($items, 'client_file_id')];
                $manifest = [];
                foreach ($items as $index => $item) {
                    $manifest[] = ['client_file_id' => (string) ($item['client_file_id'] ?? ''), 'attachment_id' => 10 + $index, 'media_id' => 'media-' . ($index + 1), 'filename' => 'safe-' . ($index + 1) . '.webp', 'original_filename' => (string) ($item['filename'] ?? ''), 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'byte_size' => 100, 'source_url' => '/anh/safe-' . ($index + 1) . '.webp', 'attachment_readback_status' => 'verified'];
                }
                if ($key === 'widget-ordinal') {
                    foreach ($manifest as $index => $item) {
                        unset($manifest[$index]['client_file_id']);
                        $manifest[$index]['ordinal'] = $index;
                    }
                }
                if ($key === 'widget-partial') {
                    return ['items' => [$manifest[0]], 'errors' => [['client_file_id' => 'file_b', 'code' => 'TRUSTED_FILE_READ_FAILED']]];
                }
                return ['batch_id' => 'batch-' . $key, 'items' => $manifest];
            },
            static function (mixed $references) use (&$materializerCalls): array {
                $materializerCalls++;
                return ['files' => ['files' => ['name' => ['a.jpg', 'b.jpg'], 'type' => ['image/jpeg', 'image/jpeg'], 'tmp_name' => ['/tmp/a', '/tmp/b'], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK], 'size' => [100, 100]]], 'temporary_paths' => []];
            },
        );

        return new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => true, imageIngest: $entrypoint);
    }

    private function call(McpTransport $transport, array $arguments): array
    {
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.media.widget-upload', 'arguments' => $arguments]]);
        self::assertSame(200, $response['status'], json_encode($response, JSON_UNESCAPED_UNICODE));
        self::assertFalse($response['body']['result']['isError'] ?? true, json_encode($response, JSON_UNESCAPED_UNICODE));
        return $response['body']['result']['structuredContent'];
    }

    private function tool(string $name): array
    {
        foreach (McpToolCatalog::tools() as $tool) if ($tool['name'] === $name) return $tool;
        self::fail('Missing tool: ' . $name);
    }

    private function readHandler(): McpReadHandler
    {
        return new McpReadHandler($this->createMock(AuthorityRepository::class), new EntityTypeRegistry(), $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class), $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class), null, $this->createMock(SourceRepository::class));
    }
}
