<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\{ControlledApplyOperationRegistry, GovernanceAutomationPolicyResolver, GovernanceAutomationTypeRegistry, GovernedSemanticIngestOrchestrator, MediaBindingStagingGuard, OperationScopedStagingGuard};
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

    public function test_staging_guard_requires_approved_capture_scope_and_exact_binding(): void
    {
        $proposal = $this->scopedMediaProposal();
        $this->stagingGuard()->assertAllowed($proposal);

        $denied = new OperationScopedStagingGuard(static fn (): string => 'staging', static fn (string $capability): bool => $capability === 'nhk_apply_proposals', scopeVerifier: static fn (array $scope, Proposal $proposal): bool => true);
        $this->expectExceptionMessage('STAGING_CAPABILITY_REQUIRED:nhk_internal_content_operations');
        $denied->assertAllowed($proposal);
    }

    public function test_staging_guard_blocks_missing_scope_wrong_media_wrong_target_and_wrong_operation(): void
    {
        $base = $this->scopedMediaProposal();
        $missing = new Proposal($base->id, $base->subjectId, $base->operation, array_diff_key($base->payload, ['staging_acceptance' => true]), $base->contentFingerprint, $base->expectedRevision, $base->dependencyFingerprint, $base->state, idempotencyKey: $base->idempotencyKey, targetUuid: $base->targetUuid, entityType: $base->entityType);
        try {
            $this->stagingGuard()->assertAllowed($missing);
            self::fail('Missing staging scope must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('STAGING_SCOPE_REQUIRED', $error->getMessage());
        }

        foreach ([
            [['media_ids' => ['01a0aefd-7e93-772c-98df-33f7abbc11e9']], 'STAGING_MEDIA_SCOPE_MISMATCH'],
            [['target' => ['type' => 'classification', 'id' => '01a09e44-539a-7f1a-938a-d7d91bb689a4']], 'STAGING_TARGET_SCOPE_MISMATCH'],
            [['operation_family' => 'knowledge_delta'], 'STAGING_OPERATION_SCOPE_MISMATCH'],
            [['writer' => 'direct_writer'], 'STAGING_DIRECT_WRITER_BLOCKED'],
            [['target' => ['type' => 'classification', 'id' => '01a09e44-539a-7f1a-938a-d7d91bb689a3', 'name' => 'Đồng hồ công cộng']], 'STAGING_EXACT_TARGET_REQUIRED'],
        ] as [$overrides, $message]) {
            try {
                $this->stagingGuard()->assertAllowed($this->scopedMediaProposal($overrides));
                self::fail('Out-of-scope staging proposal must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function test_staging_guard_blocks_missing_approval_and_production(): void
    {
        $unapproved = new OperationScopedStagingGuard(static fn (): string => 'staging', static fn (string $capability): bool => true, scopeVerifier: static fn (array $scope, Proposal $proposal): bool => false);
        try {
            $unapproved->assertAllowed($this->scopedMediaProposal());
            self::fail('Unapproved staging scope must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('STAGING_SCOPE_NOT_APPROVED', $error->getMessage());
        }

        $production = new OperationScopedStagingGuard(static fn (): string => 'production', static fn (string $capability): bool => true, scopeVerifier: static fn (array $scope, Proposal $proposal): bool => true);
        try {
            $production->assertAllowed($this->scopedMediaProposal());
            self::fail('Production semantic apply must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('STAGING_PRODUCTION_FORBIDDEN', $error->getMessage());
        }
    }

    public function test_direct_media_binding_path_is_fail_closed_without_the_same_capture_scope(): void
    {
        $guard = new MediaBindingStagingGuard(static fn (): string => 'staging');
        try {
            $guard([
                'capture_id' => UuidCodec::newV7(),
                'media' => ['id' => UuidCodec::newV7()],
                'target' => ['type' => 'classification', 'id' => UuidCodec::newV7()],
            ]);
            self::fail('Direct MediaBindingService staging calls require a verified scope.');
        } catch (\RuntimeException $error) {
            self::assertSame('STAGING_SCOPE_REQUIRED', $error->getMessage());
        }
    }

    private function stagingGuard(): OperationScopedStagingGuard
    {
        return new OperationScopedStagingGuard(
            static fn (): string => 'staging',
            static fn (string $capability): bool => in_array($capability, ['nhk_apply_proposals', 'nhk_internal_content_operations'], true),
            scopeVerifier: static fn (array $scope, Proposal $proposal): bool => ($scope['approved'] ?? false) === true,
        );
    }

    private function scopedMediaProposal(array $scopeOverrides = [], array $payloadOverrides = []): Proposal
    {
        $mediaId = '01a0aefd-7e93-772c-98df-33f7abbc11e8';
        $targetId = '01a09e44-539a-7f1a-938a-d7d91bb689a3';
        $captureId = '01a0ae4c-0fe7-72b1-8222-ece526ce0faa';
        $scope = array_replace([
            'approved' => true,
            'capture_id' => $captureId,
            'operation_family' => 'media_usage_reconciliation',
            'entity_type' => 'media',
            'operation' => 'representative_bind',
            'writer' => 'canonical_governed',
            'media_ids' => [$mediaId],
            'target' => ['type' => 'classification', 'id' => $targetId, 'stable_key' => 'nhk:classification:clock-type.dong-ho-cong-cong'],
        ], $scopeOverrides);
        $payload = array_replace_recursive([
            'binding' => ['media' => ['id' => $mediaId], 'target' => ['type' => 'classification', 'id' => $targetId], 'role' => 'representative'],
            'media_revision' => 1,
            'target_revision' => 1,
            'project_build_audit' => ['capture_id' => $captureId],
            'staging_acceptance' => $scope,
        ], $payloadOverrides);

        return new Proposal(UuidCodec::newV7(), $mediaId, 'representative_bind', $payload, 'content', 1, 'dependency', ProposalState::APPROVED, idempotencyKey: 'representative-bind', targetUuid: $targetId, entityType: 'media');
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
