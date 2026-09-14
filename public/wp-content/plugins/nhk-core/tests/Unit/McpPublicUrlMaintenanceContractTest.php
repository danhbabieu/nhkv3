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
    private const OWNER_CLOCK_TYPE = '01a07614-832d-7f27-959c-74eb0cd63f3e';

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
        self::assertSame(['owner_id', 'idempotency_key', 'pre_public_confirmed'], $tools['nhk.public-url.reproject']['inputSchema']['required']);
        self::assertSame('uuid', $tools['nhk.public-url.audit']['inputSchema']['properties']['owner_id']['format']);
        self::assertSame('nhk-v3/public-url-reproject', McpAbilityRegistration::abilityNameForTool('nhk.public-url.reproject'));
    }

    public function test_internal_public_url_reproject_can_be_explicitly_enabled_without_operator_auto_exposure(): void
    {
        $ability = 'nhk-v3/public-url-reproject';

        self::assertSame([
            $ability,
            'nhk-v3/media-widget-upload',
            'nhk-v3/proposal-submit',
            'nhk-v3/proposal-approve',
            'nhk-v3/proposal-apply',
        ], McpAbilityRegistration::explicitInternalAdminAbilityAllowlist());
        self::assertNotContains($ability, McpAbilityRegistration::ensureEasyMcpEnabledAbilities([]));
        self::assertContains($ability, McpAbilityRegistration::ensureEasyMcpEnabledAbilities([$ability]));
        self::assertNotContains('nhk-v3/media-ingest', McpAbilityRegistration::ensureEasyMcpEnabledAbilities([$ability]));
        self::assertNotContains($ability, McpAbilityRegistration::operatorEnabledAbilityAllowlist());
    }

    public function test_public_url_reproject_fails_closed_without_internal_capability(): void
    {
        $transport = new McpTransport(
            $this->read(),
            new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
            static fn (string $capability): bool => $capability !== 'nhk_internal_content_operations',
        );

        $response = $transport->dispatch($this->call('nhk.public-url.reproject', [
            'owner_id' => self::OWNER_CLOCK_TYPE,
            'idempotency_key' => 'url-capability-absent',
            'pre_public_confirmed' => true,
        ]));

        self::assertSame(200, $response['status']);
        self::assertTrue($response['body']['result']['isError']);
        self::assertSame('DIRECT_WRITE_BLOCKED', $response['body']['result']['structuredContent']['error']['code']);
    }

    public function test_transport_audit_is_read_only_and_reproject_requires_dedicated_capability(): void
    {
        $currentSlug = 'tu-i';
        $writes = 0;
        $service = new PublicUrlMaintenanceService(
            static function () use (&$currentSlug): array {
                return [[
                    'kind' => 'video',
                    'owner_id' => self::OWNER_CLOCK_TYPE,
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

        $audit = $transport->dispatch($this->call('nhk.public-url.audit', ['owner_id' => self::OWNER_CLOCK_TYPE]));
        self::assertSame(200, $audit['status']);
        self::assertSame('READY', $audit['body']['result']['structuredContent']['status'] ?? null);
        self::assertSame(0, $writes);

        $apply = $transport->dispatch($this->call('nhk.public-url.reproject', [
            'owner_id' => self::OWNER_CLOCK_TYPE,
            'idempotency_key' => 'url-cutover-1',
            'pre_public_confirmed' => true,
        ]));
        self::assertSame(200, $apply['status']);
        self::assertSame('APPLIED', $apply['body']['result']['structuredContent']['status'] ?? null);
        self::assertSame(1, $writes);
        self::assertContains('nhk_manage_public_urls', $seenCapabilities);
    }

    public function test_easy_mcp_reproject_omission_is_invalid_and_cannot_mean_global_batch(): void
    {
        $service = new PublicUrlMaintenanceService(static fn(): array => [], static fn(array $item, string $candidate): bool => false, static function(): void {});
        $transport = new McpTransport(
            $this->read(),
            new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
            static fn(string $capability): bool => true,
            null,
            publicUrls: $service,
        );

        $response = $transport->dispatch($this->call('nhk.public-url.reproject', [
            'idempotency_key' => 'global-omission',
            'pre_public_confirmed' => true,
        ]));

        self::assertSame(400, $response['status']);
        self::assertSame(-32602, $response['body']['error']['code']);
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
