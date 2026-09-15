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
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class McpWidgetUploadTest extends TestCase
{
    public function test_widget_upload_tool_declares_structured_openai_file_references(): void
    {
        $tool = $this->tool('nhk.media.widget-upload');

        self::assertSame('mutation', $tool['kind']);
        self::assertSame(['idempotency_key', 'files', 'metadata'], $tool['inputSchema']['required']);
        self::assertSame(['description'], $tool['inputSchema']['properties']['metadata']['required']);
        self::assertSame(1, $tool['inputSchema']['properties']['files']['minItems']);
        self::assertSame(20, $tool['inputSchema']['properties']['files']['maxItems']);
        self::assertSame(['download_url', 'file_id'], $tool['inputSchema']['properties']['files']['items']['required']);
        self::assertArrayNotHasKey('connectorMeta', $tool);
        $open = $this->tool('nhk.media.upload-widget.open');
        self::assertSame('ui://nhk/image-upload.html', $open['connectorMeta']['ui']['resourceUri'] ?? null);
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
        self::assertSame('/anh/safe-1.webp', $result['uploads'][0]['canonical_url']);
        self::assertSame('verified', $result['uploads'][0]['attachment_readback_status']);
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
        self::assertSame(1, $materializerCalls);
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

    private function transport(array &$calls, int &$materializerCalls): McpTransport
    {
        $entrypoint = new ImageIngestEntrypoint(
            static function (string $key, array $metadata, array $files, array $items) use (&$calls): array {
                $calls[] = [$key, $metadata, ...array_column($items, 'client_file_id')];
                $manifest = [];
                foreach ($items as $index => $item) {
                    $manifest[] = ['client_file_id' => (string) ($item['client_file_id'] ?? ''), 'attachment_id' => 10 + $index, 'media_id' => 'media-' . ($index + 1), 'filename' => 'safe-' . ($index + 1) . '.webp', 'original_filename' => (string) ($item['filename'] ?? ''), 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'byte_size' => 100, 'source_url' => '/anh/safe-' . ($index + 1) . '.webp', 'attachment_readback_status' => 'verified'];
                }
                return ['items' => $manifest];
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
        self::assertSame(200, $response['status']);
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
