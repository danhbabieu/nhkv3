<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\AuthorityCaptureService;
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Governance\GovernanceCapabilities;
use NHK\Core\Application\Mcp\{McpDocumentationRegistry, McpGovernanceHandler, McpReadHandler, McpTransport};
use NHK\Core\Infrastructure\Admin\AdminShell;
use NHK\Core\Application\Runtime\SemanticWritePolicyResolver;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class SemanticWritePolicyMcpGateTest extends TestCase
{
    public function test_read_only_blocks_brand_model_and_clock_type_plan_before_planner(): void
    {
        $plannerCalls = 0;
        $transport = $this->transport('read_only', 'staging', static function () use (&$plannerCalls): AuthorityCaptureService {
            return new AuthorityCaptureService(new PolicyMcpCaptureRepository(), static function () use (&$plannerCalls): array {
                ++$plannerCalls;
                return [];
            });
        });

        foreach (['Tạo thương hiệu Hermle.', 'Tạo model Odo 36.', 'Tạo loại Đồng hồ công cộng.'] as $index => $text) {
            $result = $this->plan($transport, 'read-only-' . $index, $text);
            self::assertTrue($result['body']['result']['isError']);
            self::assertSame('SEMANTIC_WRITE_POLICY_READ_ONLY', $result['body']['result']['structuredContent']['error']['code']);
        }

        self::assertSame(0, $plannerCalls);
    }

    public function test_project_build_without_capability_blocks_brand_model_and_clock_type_plan(): void
    {
        $transport = $this->transport('project_build', 'development', static fn (): AuthorityCaptureService => new AuthorityCaptureService(
            new PolicyMcpCaptureRepository(),
            static fn (): array => throw new \LogicException('planner must not be called'),
        ), static fn (string $capability): bool => in_array($capability, ['read', 'nhk_ingest_articles'], true));

        foreach (['Tạo thương hiệu Hermle.', 'Tạo model Odo 36.', 'Tạo loại Đồng hồ công cộng.'] as $index => $text) {
            $result = $this->plan($transport, 'missing-capability-' . $index, $text);
            self::assertTrue($result['body']['result']['isError']);
            self::assertSame('PROJECT_BUILD_CAPABILITY_REQUIRED', $result['body']['result']['structuredContent']['error']['code']);
        }
    }

    public function test_project_build_with_capability_allows_canonical_plan_for_brand_model_and_clock_type(): void
    {
        $transport = $this->transport('project_build', 'staging-build', static fn (): AuthorityCaptureService => new AuthorityCaptureService(
            new PolicyMcpCaptureRepository(),
            static fn (): array => ['reuse' => [], 'create_candidates' => [], 'update_candidates' => [], 'relation_candidates' => [], 'plan_fingerprint' => str_repeat('a', 64)],
        ));

        foreach (['Tạo thương hiệu Hermle.', 'Tạo model Odo 36.', 'Tạo loại Đồng hồ công cộng.'] as $index => $text) {
            $result = $this->plan($transport, 'project-build-' . $index, $text);
            self::assertFalse($result['body']['result']['isError']);
            self::assertSame('PLANNED', $result['body']['result']['structuredContent']['status']);
        }
    }

    public function test_production_project_build_is_forbidden_even_with_all_capabilities(): void
    {
        $transport = $this->transport('project_build', 'production', null, static fn (string $capability): bool => true);
        $result = $this->plan($transport, 'production-project-build', 'Tạo loại Đồng hồ công cộng.');

        self::assertTrue($result['body']['result']['isError']);
        self::assertSame('PROJECT_BUILD_FORBIDDEN_IN_PRODUCTION', $result['body']['result']['structuredContent']['error']['code']);
    }

    public function test_locked_operational_preserves_existing_canonical_governance_path(): void
    {
        $transport = $this->transport('locked_operational', 'staging', static fn (): AuthorityCaptureService => new AuthorityCaptureService(
            new PolicyMcpCaptureRepository(),
            static fn (): array => ['reuse' => [], 'create_candidates' => [], 'update_candidates' => [], 'relation_candidates' => [], 'plan_fingerprint' => str_repeat('a', 64)],
        ), static fn (string $capability): bool => true);
        $result = $this->plan($transport, 'locked-project-build', 'Tạo thương hiệu Hermle.');

        self::assertFalse($result['body']['result']['isError']);
        self::assertSame('PLANNED', $result['body']['result']['structuredContent']['status']);
    }

    public function test_direct_writer_remains_blocked_in_project_build(): void
    {
        $transport = $this->transport('project_build', 'development', null, static fn (string $capability): bool => $capability === 'read' || $capability === 'nhk_create_proposals');
        foreach (['nhk.proposal.create', 'nhk.knowledge.ingest', 'nhk.relation.backfill.apply'] as $index => $tool) {
            $result = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 7 + $index, 'method' => 'tools/call', 'params' => [
                'name' => $tool,
                'arguments' => ['stable_key' => 'direct.blocked.' . $index, 'text' => 'blocked', 'candidates' => [], 'approval_confirmed' => false],
            ]]);

            self::assertTrue($result['body']['result']['isError'], $tool);
            self::assertSame('DIRECT_WRITE_BLOCKED', $result['body']['result']['structuredContent']['error']['code'], $tool);
        }
    }

    public function test_read_tools_remain_available_in_read_only(): void
    {
        $transport = $this->transport('read_only', 'staging', null, static fn (string $capability): bool => $capability === 'read');
        $result = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => [
            'name' => 'nhk.documentation.bootstrap',
            'arguments' => [],
        ]]);

        self::assertSame(200, $result['status']);
        self::assertFalse($result['body']['result']['isError']);
    }

    public function test_runtime_identity_exposes_policy_on_discovery_and_documentation_bootstrap(): void
    {
        $resolver = new SemanticWritePolicyResolver(static fn (): string => 'project_build', static fn (): string => 'staging');
        $documentation = new McpDocumentationRegistry(null, null, $resolver);
        $transport = new McpTransport(
            $this->readHandler(),
            new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
            static fn (string $capability): bool => $capability === 'read',
            documentation: $documentation,
            semanticWritePolicy: $resolver,
        );

        $discovery = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'server/discover', 'params' => []]);
        $identity = $discovery['body']['result']['runtime_identity'];
        self::assertSame('staging', $identity['environment']);
        self::assertSame('PROJECT_BUILD', $identity['semantic_write_policy']);
        self::assertTrue($identity['project_build_enabled']);
        self::assertArrayHasKey('runtime_version', $identity);
        self::assertArrayHasKey('build_identity', $identity);
        self::assertArrayHasKey('documentation_version', $identity);
        self::assertArrayHasKey('manifest_hash', $identity);

        $bootstrap = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => ['name' => 'nhk.documentation.bootstrap', 'arguments' => []]]);
        self::assertSame('PROJECT_BUILD', $bootstrap['body']['result']['structuredContent']['semantic_write_policy']);
        self::assertTrue($bootstrap['body']['result']['structuredContent']['project_build_enabled']);
    }

    public function test_project_build_capability_is_registered_without_replacing_governance_capabilities(): void
    {
        self::assertContains('nhk_project_build_semantic', GovernanceCapabilities::ALL);
        self::assertContains('nhk_project_build_semantic', AdminShell::capabilityNames());
        self::assertContains('nhk_approve_proposals', GovernanceCapabilities::ALL);
        self::assertContains('nhk_apply_proposals', GovernanceCapabilities::ALL);
        self::assertNotSame('nhk_project_build_semantic', 'nhk_approve_proposals');
    }

    public function test_plugin_wires_one_runtime_policy_into_documentation_and_mcp_transport(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Plugin.php');
        self::assertIsString($source);
        self::assertStringContainsString('new SemanticWritePolicyResolver()', $source);
        self::assertStringContainsString('new McpDocumentationRegistry(null, null, $semanticWritePolicy)', $source);
        self::assertStringContainsString('semanticWritePolicy: $semanticWritePolicy', $source);
    }

    private function transport(string $policy, string $environment, ?callable $authorityFactory = null, ?callable $can = null): McpTransport
    {
        $documentation = new McpDocumentationRegistry();
        $authority = $authorityFactory === null ? null : $authorityFactory();
        return new McpTransport(
            $this->readHandler(),
            new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
            $can ?? static fn (string $capability): bool => in_array($capability, ['read', 'nhk_ingest_articles', 'nhk_project_build_semantic'], true),
            documentation: $documentation,
            authorityCapture: $authority,
            semanticWritePolicy: new SemanticWritePolicyResolver(static fn (): string => $policy, static fn (): string => $environment),
        );
    }

    private function plan(McpTransport $transport, string $key, string $text): array
    {
        $checkpoint = (new McpDocumentationRegistry())->bootstrap();
        return $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => [
            'idempotency_key' => $key,
            'purpose' => 'AUTHORITY',
            'text' => $text,
            'authority_intent' => ['mode' => 'PLAN'],
            'documentation_checkpoint' => ['documentation_version' => $checkpoint['documentation_version'], 'manifest_hash' => $checkpoint['manifest_hash']],
        ]]]);
    }

    private function readHandler(): McpReadHandler
    {
        return new McpReadHandler($this->createMock(AuthorityRepository::class), new EntityTypeRegistry(), $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class), $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class), null, $this->createMock(SourceRepository::class));
    }
}

final class PolicyMcpCaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    private array $records = [];

    public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }
    public function findById(string $captureId): ?CaptureRecord { foreach ($this->records as $record) if ($record->captureId === $captureId) return $record; return null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] ??= $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
}
