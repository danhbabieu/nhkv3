<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\{ControlledApplyOperationRegistry, GovernanceAutomationPolicyResolver, GovernanceAutomationTypeRegistry, GovernedSemanticIngestOrchestrator, OperationScopedStagingGuard};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Governance\{AutomationMode, Proposal, ProposalState};
use NHK\Core\Contracts\Governance\{AutomationPolicyStorage, GovernedLifecycle};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class GovernanceAutomationExpansionTest extends TestCase
{
    public function test_movement_generation_and_type_are_registered_optional_authority_fields(): void
    {
        $catalog = CanonicalEntityTypeCatalog::definitions();
        $movement = array_values(array_filter($catalog, static fn ($definition): bool => $definition->type === 'movement'))[0];

        self::assertSame('1.1.0', CanonicalEntityTypeCatalog::VERSION);
        self::assertSame(2, $movement->schemaVersion);
        self::assertSame('string', $movement->fieldTypes['generation']);
        self::assertSame('string', $movement->fieldTypes['movement_type']);
        self::assertContains('generation', $movement->allowedFields);
        self::assertContains('movement_type', $movement->allowedFields);
    }

    public function test_expansion_uses_media_representative_operation_and_shared_owner_registry(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);

        self::assertTrue((new ControlledApplyOperationRegistry())->supports('media', 'representative_bind'));
        self::assertFalse((new ControlledApplyOperationRegistry())->supports('media_usage', 'representative_bind'));
        self::assertContains('brand', GovernanceAutomationTypeRegistry::all($types));
        self::assertContains('relation', GovernanceAutomationTypeRegistry::all($types));
        self::assertNotContains('media_usage', GovernanceAutomationTypeRegistry::all($types));
    }

    public function test_staging_guard_is_operation_and_capability_scoped_without_object_allowlist(): void
    {
        $proposal = new Proposal(
            UuidCodec::newV7(),
            UuidCodec::newV7(),
            'representative_bind',
            ['binding' => ['role' => 'representative'], 'media_revision' => 1, 'target_revision' => 1],
            'content',
            1,
            'dependency',
            ProposalState::APPROVED,
            idempotencyKey: 'representative-bind',
            targetUuid: UuidCodec::newV7(),
            entityType: 'media',
        );
        $guard = new OperationScopedStagingGuard(static fn (): string => 'staging', static fn (string $capability): bool => in_array($capability, ['nhk_apply_proposals', 'nhk_internal_content_operations'], true));
        $guard->assertAllowed($proposal);

        $denied = new OperationScopedStagingGuard(static fn (): string => 'staging', static fn (string $capability): bool => $capability === 'nhk_apply_proposals');
        $this->expectExceptionMessage('STAGING_CAPABILITY_REQUIRED:nhk_internal_content_operations');
        $denied->assertAllowed($proposal);
    }

    public function test_article_auto_publish_uses_the_publication_boundary(): void
    {
        $proposal = new Proposal(UuidCodec::newV7(), '1:575', 'relation_create', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'article-publication', entityType: 'wp_post');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->method('createFromArguments')->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal);
        $governance->method('review')->willReturn(['state' => 'submitted', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('approve')->willReturn($proposal->transition(ProposalState::APPROVED, '0'));
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $publicationCalls = 0;
        $runner = new GovernedSemanticIngestOrchestrator(
            $governance,
            static fn (array $review): bool => true,
            static fn (string $id): array => ['canonical_readback' => ['canonical_id' => UuidCodec::newV7(), 'entity_type' => 'relation', 'active' => true, 'revision' => 1, 'snapshot' => []]],
            new GovernanceAutomationPolicyResolver(['wp_post'], new class implements AutomationPolicyStorage {
                public function read(): array { return ['wp_post' => 'AUTO_PUBLISH']; }
                public function write(array $policies): void {}
            }),
            null,
            null,
            null,
            static function (Proposal $approved, array $applied) use (&$publicationCalls): array {
                $publicationCalls++;
                return ['frontend_available' => true, 'public_url' => 'https://demo.1945.vn/bai'];
            },
        );
        $result = $runner->run([['operation' => 'relation_create', 'entity_type' => 'wp_post', 'payload' => []]])[0];

        self::assertSame('published', $result['status']);
        self::assertSame('article_publication', $result['gate_reached']);
        self::assertSame(1, $publicationCalls);
    }
}
