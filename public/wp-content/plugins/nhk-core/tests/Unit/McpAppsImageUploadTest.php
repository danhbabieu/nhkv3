<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Mcp\{McpAppsResourceRegistry, McpGovernanceHandler, McpReadHandler, McpTransport};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class McpAppsImageUploadTest extends TestCase
{
    public function test_image_resource_is_listed_and_read_as_mcp_app_html(): void
    {
        $listed = McpAppsResourceRegistry::list();
        self::assertSame('ui://nhk/image-upload/v2.html', $listed['resources'][0]['uri']);
        self::assertSame('text/html;profile=mcp-app', $listed['resources'][0]['mimeType']);
        self::assertSame(['ui' => ['prefersBorder' => true]], $listed['resources'][0]['_meta']);

        $resource = McpAppsResourceRegistry::read('ui://nhk/image-upload/v2.html');
        self::assertSame('ui://nhk/image-upload/v2.html', $resource['contents'][0]['uri']);
        self::assertSame('text/html;profile=mcp-app', $resource['contents'][0]['mimeType']);
        self::assertNotSame('', trim($resource['contents'][0]['text']));
        self::assertSame(['ui' => ['prefersBorder' => true]], $resource['contents'][0]['_meta']);
        self::assertStringContainsString('uploadFile', $resource['contents'][0]['text']);
        self::assertStringContainsString('getFileDownloadUrl', $resource['contents'][0]['text']);
        self::assertStringContainsString('setWidgetState', $resource['contents'][0]['text']);
        self::assertStringContainsString('wp_ability_nhk_v3_capture_ingest', $resource['contents'][0]['text']);
        self::assertStringContainsString('Ảnh được tải trước; bài viết có thể tạo sau từ đúng Media vừa tải.', $resource['contents'][0]['text']);
    }

    public function test_unknown_fixed_ui_resource_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        McpAppsResourceRegistry::read('ui://nhk/image-upload/v3.html');
    }

    public function test_mcp_transport_exposes_resource_methods_and_render_tool(): void
    {
        $transport = new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => true);

        $list = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list', 'params' => []]);
        self::assertSame(200, $list['status']);
        self::assertSame('ui://nhk/image-upload/v2.html', $list['body']['result']['resources'][0]['uri']);

        $read = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'ui://nhk/image-upload/v2.html']]);
        self::assertSame(200, $read['status']);
        self::assertStringContainsString('Ảnh được tải trước; bài viết có thể tạo sau từ đúng Media vừa tải.', $read['body']['result']['contents'][0]['text']);

        $open = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'nhk.media.upload-widget.open', 'arguments' => []]]);
        self::assertSame(200, $open['status']);
        self::assertFalse($open['body']['result']['isError']);
        self::assertSame('ui://nhk/image-upload/v2.html', $open['body']['result']['structuredContent']['resourceUri']);
    }

    public function test_widget_bootstrap_has_a_visible_error_fallback(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../../../../tools/mcp-apps/image-upload/src/app.ts');
        self::assertStringContainsString('function showBootstrapError', $source);
        self::assertStringContainsString('BOOTSTRAP_INIT_FAILED', $source);
        self::assertStringContainsString('Không thể khởi tạo trình tải ảnh NHK', $source);
    }

    private function readHandler(): McpReadHandler
    {
        return new McpReadHandler($this->createMock(AuthorityRepository::class), new EntityTypeRegistry(), $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class), $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class), null, $this->createMock(SourceRepository::class));
    }
}
