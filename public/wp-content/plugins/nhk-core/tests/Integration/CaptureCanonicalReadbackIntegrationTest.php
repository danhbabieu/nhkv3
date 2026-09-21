<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpReadHandler, McpTransport};
use NHK\Core\Contracts\{Authority\AuthorityRepository, Knowledge\EvidenceRepository, Knowledge\KnowledgeRepository, Media\MediaAssetRepository, Media\MediaRepository, Media\MediaUsageRepository, Video\VideoRepository};
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Infrastructure\Capture\WpdbCaptureRepository;
use NHK\Core\Infrastructure\Migration\EditorialCaptureMigration017;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\{InMemoryProposalRepository, TestDatabaseGuard};
use PHPUnit\Framework\TestCase;

final class CaptureCanonicalReadbackIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        (new EditorialCaptureMigration017())->up();
    }

    public function test_capture_write_then_public_transport_get_uses_same_canonical_store(): void
    {
        global $wpdb;
        $id = UuidCodec::newV7();
        $repository = new WpdbCaptureRepository($wpdb);
        $repository->create(new CaptureRecord($id, 'integration-capture-' . $id, hash('sha256', $id), 'REVIEW_REQUIRED', 'PARTIAL', null, null, [], ['content_intent' => ['intent' => 'VIDEO']], ['completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['STAGING_SCOPE_REQUIRED']]], []));
        try {
            $read = new McpReadHandler(
                $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
                $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
                $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
                captures: $repository,
            );
            $transport = new McpTransport($read, new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => true);
            $found = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.get', 'arguments' => ['id' => $id]]], ['Mcp-Name' => 'nhk.capture.get']);
            $unknown = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.get', 'arguments' => ['id' => '11111111-1111-4111-8111-111111111111']]], ['Mcp-Name' => 'nhk.capture.get']);
            $ability = wp_get_ability('nhk-v3/capture-get');
            self::assertNotNull($ability);
            $abilityFound = $ability->execute(['id' => $id]);
            self::assertSame('available', $found['body']['result']['structuredContent']['status']);
            self::assertSame('REVIEW_REQUIRED', $found['body']['result']['structuredContent']['intent']['intent']);
            self::assertSame('not_found', $unknown['body']['result']['structuredContent']['status']);
            self::assertSame('CAPTURE_NOT_FOUND', $unknown['body']['result']['structuredContent']['reason']);
            self::assertSame('available', $abilityFound['status']);
        } finally {
            TestDatabaseGuard::assertDestructiveAllowed((string) $wpdb->get_var('SELECT DATABASE()'));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_editorial_captures WHERE capture_uuid=%s', UuidCodec::toBinary($id)));
        }
    }
}
