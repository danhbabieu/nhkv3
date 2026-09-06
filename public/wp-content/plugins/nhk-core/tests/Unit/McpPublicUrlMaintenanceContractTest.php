<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpGovernanceHandler, McpReadHandler, McpToolCatalog, McpTransport};
use NHK\Core\Application\PublicIdentity\PublicUrlMaintenanceService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class McpPublicUrlMaintenanceContractTest extends TestCase
{
    public function test_catalog_and_ability_bridge_expose_bounded_public_url_actions(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');

        self::assertArrayHasKey('nhk.public-url.audit', $tools);
        self::assertSame('read', $tools['nhk.public-url.audit']['kind']);
        self::assertFalse($tools['nhk.public-url.audit']['governed']);
        self::assertSame('nhk-v3/public-url-audit', McpAbilityRegistration::abilityNameForTool('nhk.public-url.audit'));

        self::assertArrayHasKey('nhk.public-url.reproject', $tools);
        self::assertSame('mutation', $tools['nhk.public-url.reproject']['kind']);
        self::assertTrue($tools['nhk.public-url.reproject']['governed']);
        self::assertSame(['idempotency_key', 'pre_public_confirmed'], $tools['nhk.public-url.reproject']['inputSchema']['required']);
        self::assertSame('nhk-v3/public-url-reproject', McpAbilityRegistration::abilityNameForTool('nhk.public-url.reproject'));
    }

    public function test_transport_audit_is_read_only_and_reproject_requires_dedicated_capability(): void
    {
        $currentSlug = 'tu-i';
        $writes = 0;
        $service = new PublicUrlMaintenanceService(
            static function () use (&$currentSlug): array {
                return [[
                    'kind' => 'video',
                    'owner_id' => 'video-1',
                    'route_type' => 'video',
                    'scope' => 'root',
                    'name' => 'Tuổi',
                    'current_slug' => $currentSlug,
                    'qualifiers' => [],
                ]];
            },
            static fn (array $item, string $candidate): bool => false,
            static function (array $item, string $idempotencyKey) use (&$currentSlug, &$writes): void {
                $currentSlug = (string) $item['desired_slug'];
                $writes++;
            },
        );

        $seenCapabilities = [];
        $transport = new McpTransport(
            $this->read(),
            new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
            static function (string $capability) use (&$seenCapabilities): bool {
                $seenCapabilities[] = $capability;
                return true;
            },
            null,
            publicUrls: $service,
        );

        $audit = $transport->dispatch($this->call('nhk.public-url.audit', []));
        self::assertSame(200, $audit['status']);
        self::assertSame('READY', $audit['body']['result']['structuredContent']['status'] ?? null);
        self::assertSame(0, $writes);

        $apply = $transport->dispatch($this->call('nhk.public-url.reproject', [
            'idempotency_key' => 'url-cutover-1',
            'pre_public_confirmed' => true,
        ]));
        self::assertSame(200, $apply['status']);
        self::assertSame('APPLIED', $apply['body']['result']['structuredContent']['status'] ?? null);
        self::assertSame(1, $writes);
        self::assertContains('nhk_manage_public_urls', $seenCapabilities);
    }

    private function call(string $name, array $arguments): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]];
    }

    private function read(): McpReadHandler
    {
        return new McpReadHandler(
            $this->createMock(AuthorityRepository::class),
            new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class),
            $this->createMock(MediaAssetRepository::class),
            $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class),
            $this->createMock(KnowledgeRepository::class),
            $this->createMock(EvidenceRepository::class),
            null,
            $this->createMock(SourceRepository::class),
            null,
            null,
            null,
        );
    }
}
