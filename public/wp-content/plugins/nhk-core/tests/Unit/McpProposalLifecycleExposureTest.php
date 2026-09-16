<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpToolCatalog, SingleEntryPointPolicy};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpReadHandler, McpTransport};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class McpProposalLifecycleExposureTest extends TestCase
{
    public function test_proposal_lifecycle_abilities_are_explicit_easy_mcp_internal_admin_opt_ins(): void
    {
        $expectedAbilities = [
            'nhk-v3/proposal-submit',
            'nhk-v3/proposal-approve',
            'nhk-v3/proposal-apply',
        ];

        self::assertSame(
            $expectedAbilities,
            array_values(array_intersect($expectedAbilities, McpAbilityRegistration::explicitInternalAdminAbilityAllowlist()))
        );
        self::assertSame(
            $expectedAbilities,
            array_values(array_intersect($expectedAbilities, McpAbilityRegistration::ensureEasyMcpEnabledAbilities($expectedAbilities)))
        );
    }

    public function test_connector_discovery_exposes_only_the_explicit_semantic_admin_continuations(): void
    {
        $expected = [
            'nhk.article.ingest' => 'nhk-v3/article-ingest',
            'nhk.source.ingest' => 'nhk-v3/source-ingest',
            'nhk.evidence.ingest' => 'nhk-v3/evidence-ingest',
            'nhk.proposal.create' => 'nhk-v3/proposal-create',
        ];

        $contract = McpAbilityRegistration::exposureContract();
        foreach ($expected as $tool => $ability) {
            self::assertContains($ability, McpAbilityRegistration::explicitInternalAdminAbilityAllowlist(), $tool);
            self::assertTrue($contract[$tool]['easy_mcp_descriptor_exposed'], $tool);
            self::assertTrue($contract[$tool]['connector_discoverable'], $tool);
            self::assertNotContains($ability, McpAbilityRegistration::operatorEnabledAbilityAllowlist(), $tool);
            self::assertNotContains($ability, McpAbilityRegistration::ensureEasyMcpEnabledAbilities([]), $tool);
            self::assertSame($ability, McpAbilityRegistration::abilityNameForConnectorTool(McpAbilityRegistration::connectorToolNameForAbility($ability)), $tool);
        }

        self::assertFalse(McpAbilityRegistration::exposureContract()['nhk.knowledge.ingest']['easy_mcp_descriptor_exposed']);
    }

    public function test_article_draft_update_is_a_narrow_always_discoverable_continuation_opt_in(): void
    {
        $tool = array_column(McpToolCatalog::tools(), null, 'name')['nhk.article.draft.update'];
        self::assertSame('nhk-v3/article-draft-update', McpAbilityRegistration::abilityNameForTool('nhk.article.draft.update'));
        self::assertTrue(SingleEntryPointPolicy::isInternalOnly('nhk.article.draft.update'));
        self::assertSame('mutation', $tool['kind']);
        self::assertTrue($tool['governed']);
        self::assertSame('internal_admin_only', $tool['surface']);
        self::assertSame(['post_id', 'fields', 'expected_state_token'], $tool['inputSchema']['required']);
        self::assertContains('nhk-v3/article-draft-update', McpAbilityRegistration::explicitInternalAdminAbilityAllowlist());
        self::assertContains('nhk-v3/article-draft-update', McpAbilityRegistration::ensureEasyMcpEnabledAbilities([]));
        self::assertContains('nhk-v3/article-publish-review', McpAbilityRegistration::ensureEasyMcpEnabledAbilities([]));
    }

    public function test_proposal_lifecycle_tools_keep_internal_surface_and_callable_catalog_schemas(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        $expected = [
            'nhk.proposal.submit' => ['ability' => 'nhk-v3/proposal-submit', 'required' => ['id']],
            'nhk.proposal.approve' => ['ability' => 'nhk-v3/proposal-approve', 'required' => ['id', 'content_fingerprint', 'dependency_fingerprint']],
            'nhk.proposal.apply' => ['ability' => 'nhk-v3/proposal-apply', 'required' => ['id']],
        ];

        foreach ($expected as $tool => $contract) {
            self::assertArrayHasKey($tool, $tools);
            self::assertContains($contract['ability'], McpAbilityRegistration::abilityNames(), $tool);
            self::assertSame('mutation', $tools[$tool]['kind'], $tool);
            self::assertTrue($tools[$tool]['governed'], $tool);
            self::assertSame('internal_admin_only', $tools[$tool]['surface'], $tool);
            self::assertSame($contract['required'], $tools[$tool]['inputSchema']['required'], $tool);
            self::assertSame(SingleEntryPointPolicy::surface($tool), $tools[$tool]['surface'], $tool);
            self::assertSame($contract['ability'], McpAbilityRegistration::abilityNameForTool($tool), $tool);
        }
    }

    public function test_proposal_lifecycle_requires_internal_admin_boundary(): void
    {
        foreach (['nhk.proposal.submit', 'nhk.proposal.approve', 'nhk.proposal.apply'] as $tool) {
            $transport = new McpTransport(
                $this->readHandler(),
                new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
                static fn (string $capability): bool => $capability !== SingleEntryPointPolicy::INTERNAL_CAPABILITY,
            );

            $response = $transport->dispatch($this->call($tool, []));

            self::assertSame(200, $response['status'], $tool);
            self::assertTrue($response['body']['result']['isError'], $tool);
            self::assertSame('DIRECT_WRITE_BLOCKED', $response['body']['result']['structuredContent']['error']['code'], $tool);
        }
    }

    public function test_proposal_create_invalid_input_reaches_canonical_dispatch_instead_of_unknown_tool(): void
    {
        $transport = new McpTransport(
            $this->readHandler(),
            new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
            static fn (string $capability): bool => true,
        );

        $response = $transport->dispatch($this->call('nhk.proposal.create', []));

        self::assertSame(400, $response['status']);
        self::assertSame(-32602, $response['body']['error']['code'] ?? null);
        self::assertNotSame(-32601, $response['body']['error']['code'] ?? null);
    }

    public function test_proposal_lifecycle_keeps_action_specific_capability_guards(): void
    {
        $required = [
            'nhk.proposal.submit' => 'nhk_submit_proposals',
            'nhk.proposal.approve' => 'nhk_approve_proposals',
            'nhk.proposal.apply' => 'nhk_apply_proposals',
        ];

        foreach ($required as $tool => $capability) {
            $transport = new McpTransport(
                $this->readHandler(),
                new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
                static function (string $candidate) use ($capability): bool { return $candidate !== $capability; },
            );

            $response = $transport->dispatch($this->call($tool, []));

            self::assertSame(403, $response['status'], $tool);
            self::assertSame(-32003, $response['body']['error']['code'], $tool);
        }
    }

    public function test_submit_and_approve_are_exposed_as_separate_non_applying_lifecycle_steps(): void
    {
        $repository = new InMemoryProposalRepository();
        $service = new GovernanceService($repository);
        $proposal = $service->create(new Proposal(
            UuidCodec::newV7(),
            'classification',
            'create',
            ['stable_key' => 'nhk:classification:clock-type.test'],
            str_repeat('a', 64),
            null,
            str_repeat('b', 64),
            ProposalState::DRAFT,
            actor: '1',
            idempotencyKey: 'mcp-proposal-lifecycle-test',
            entityType: 'classification',
        ));
        $transport = new McpTransport(
            $this->readHandler(),
            new McpGovernanceHandler($service),
            static fn (string $capability): bool => true,
        );

        $submitted = $transport->dispatch($this->call('nhk.proposal.submit', ['id' => $proposal->id]));
        self::assertSame('submitted', $submitted['body']['result']['structuredContent']['state'] ?? null, (string) json_encode($submitted, JSON_UNESCAPED_UNICODE));

        $approved = $transport->dispatch($this->call('nhk.proposal.approve', [
            'id' => $proposal->id,
            'content_fingerprint' => str_repeat('a', 64),
            'dependency_fingerprint' => str_repeat('b', 64),
        ]));
        self::assertSame('approved', $approved['body']['result']['structuredContent']['state']);
        self::assertSame('approved', $repository->find($proposal->id)?->state->value);
        self::assertArrayNotHasKey('canonical_id', $approved['body']['result']['structuredContent']);
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function call(string $name, array $arguments): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ];
    }

    private function readHandler(): McpReadHandler
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
