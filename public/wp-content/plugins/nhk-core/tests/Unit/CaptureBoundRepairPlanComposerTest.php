<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CaptureBoundRepairPlanComposer;
use NHK\Core\Application\Governance\{CaptureDependencyStagingAdmission, StagingAcceptanceScopeVerifier};
use NHK\Core\Application\Knowledge\{CanonicalDependencyValidator, KnowledgeRepairPreviewService};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CaptureBoundRepairPlanComposerTest extends TestCase
{
    public function test_batch_repair_composition_binds_each_owner_revision_dependencies_and_capture(): void
    {
        [$capture, $claims, $source, $evidence, $composer] = $this->fixture();
        $operations = [];
        foreach ($claims as $claim) {
            $operations[] = [
                'entity_type' => 'knowledge',
                'operation' => 'retire',
                'canonical_owner_id' => $claim->canonicalId,
                'expected_revision' => $claim->revision,
                'reason' => 'Process contamination repair.',
                'provenance' => ['source' => 'owner_review'],
                'cleanup_class' => 'PROCESS_CONTAMINATION',
            ];
        }
        $operations[] = [
            'entity_type' => 'evidence',
            'operation' => 'update',
            'canonical_owner_id' => $evidence->canonicalId,
            'expected_revision' => $evidence->revision,
            'reason' => 'Reconcile provenance relation.',
            'provenance' => ['source' => 'owner_review'],
            'payload' => ['excerpt' => 'Canonical excerpt', 'relation' => 'supports'],
        ];

        $plans = $composer->compose($capture->captureId, ['operations' => $operations]);

        self::assertCount(6, $plans);
        self::assertSame(range(0, 5), array_column($plans, 'repair_plan_index'));
        foreach ($plans as $plan) {
            self::assertSame($capture->captureId, $plan['payload']['capture_id']);
            self::assertSame($plan['target_uuid'], $plan['payload']['canonical_owner_id']);
            self::assertSame($plan['expected_revision'], $plan['payload']['canonical_owner_revision']);
            self::assertStringStartsWith('capture:' . $capture->captureId . ':repair:', $plan['idempotency_key']);
            self::assertNotSame('', $plan['repair_plan_fingerprint']);
        }

        $knowledge = $plans[0];
        self::assertSame('knowledge_delta', $this->scope($capture, $knowledge)['operation_family']);
        self::assertFalse($knowledge['payload']['repair']['manual_review_required']);
        self::assertSame('RESOLVED', $knowledge['payload']['repair']['identity_binding']['classification']);

        $evidencePlan = $plans[5];
        self::assertSame('source_evidence_reconciliation', $this->scope($capture, $evidencePlan)['operation_family']);
        self::assertStringStartsWith('capture:' . $capture->captureId . ':repair:', $evidencePlan['idempotency_key']);
        self::assertSame([$claims[0]->canonicalId, $source->canonicalId], array_values(array_intersect($evidencePlan['dependency_ids'], [$claims[0]->canonicalId, $source->canonicalId])));
        self::assertSame($claims[0]->revision, $evidencePlan['dependency_revisions'][$claims[0]->canonicalId]);
        self::assertSame($source->revision, $evidencePlan['dependency_revisions'][$source->canonicalId]);
    }

    public function test_owner_revision_drift_is_fail_closed_before_packet_issuance(): void
    {
        [$capture, $claims, , , $composer] = $this->fixture();
        $input = [
            'operations' => [[
                'entity_type' => 'knowledge', 'operation' => 'retire',
                'canonical_owner_id' => $claims[0]->canonicalId, 'expected_revision' => $claims[0]->revision - 1,
                'reason' => 'Stale repair.', 'provenance' => ['source' => 'owner_review'], 'cleanup_class' => 'PROCESS_CONTAMINATION',
            ]],
        ];

        $this->expectExceptionMessage('CAPTURE_REPAIR_CANONICAL_OWNER_REVISION_CHANGED');
        $composer->compose($capture->captureId, $input);
    }

    public function test_packet_binds_content_dependency_fingerprints_capabilities_and_idempotency(): void
    {
        [$capture, $claims, , , $composer] = $this->fixture();
        $plan = $composer->compose($capture->captureId, ['operations' => [[
            'entity_type' => 'knowledge', 'operation' => 'retire',
            'canonical_owner_id' => $claims[0]->canonicalId, 'expected_revision' => $claims[0]->revision,
            'reason' => 'Process contamination repair.', 'provenance' => ['source' => 'owner_review'], 'cleanup_class' => 'PROCESS_CONTAMINATION',
        ]]])[0];

        $packet = $this->scope($capture, $plan);
        self::assertSame($capture->captureId, $packet['capture_id']);
        self::assertSame($capture->revision, $packet['capture_revision']);
        self::assertSame($plan['target_uuid'], $packet['canonical_owner_id']);
        self::assertSame($plan['expected_revision'], $packet['canonical_owner_revision']);
        self::assertSame($packet['payload_fingerprint'], $packet['content_fingerprint']);
        self::assertSame($plan['idempotency_key'], $packet['idempotency_key']);
        self::assertSame(['nhk_internal_content_operations', 'nhk_apply_proposals'], $packet['required_capabilities']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $packet['dependency_fingerprint']);
        $verifier = new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'test-secret', static fn (): bool => true, can: static fn (): bool => true);
        $proposal = new Proposal(UuidCodec::newV7(), $plan['subject_id'], $plan['operation'], $plan['payload'] + ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'staging_acceptance' => $packet], 'content', $plan['expected_revision'], 'dependency', ProposalState::APPROVED, idempotencyKey: $plan['idempotency_key'], targetUuid: $plan['target_uuid'], entityType: $plan['entity_type']);
        self::assertNull($verifier->proposalFailureReason($packet, $proposal));
    }

    public function test_expired_capture_packet_is_rejected_even_when_the_command_is_unchanged(): void
    {
        [$capture, $claims, , , $composer] = $this->fixture();
        $plan = $composer->compose($capture->captureId, ['operations' => [[
            'entity_type' => 'knowledge', 'operation' => 'retire',
            'canonical_owner_id' => $claims[0]->canonicalId, 'expected_revision' => $claims[0]->revision,
            'reason' => 'Process contamination repair.', 'provenance' => ['source' => 'owner_review'], 'cleanup_class' => 'PROCESS_CONTAMINATION',
        ]]])[0];
        $verifier = new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'test-secret', static fn (): bool => true, can: static fn (): bool => true);
        $packet = $verifier->issueForCaptureDependencyPlan($capture, $plan);
        $proposal = new Proposal(UuidCodec::newV7(), $plan['subject_id'], $plan['operation'], $plan['payload'] + ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'staging_acceptance' => $packet], 'content', $plan['expected_revision'], 'dependency', ProposalState::APPROVED, idempotencyKey: $plan['idempotency_key'], targetUuid: $plan['target_uuid'], entityType: $plan['entity_type']);

        $expired = $packet;
        $expired['expires_at'] = gmdate('c', time() - 1);
        self::assertSame('STAGING_SCOPE_NOT_APPROVED', $verifier->proposalFailureReason($expired, $proposal));
    }

    /** @return array{0:CaptureRecord,1:list<KnowledgeClaim>,2:Source,3:Evidence,4:CaptureBoundRepairPlanComposer} */
    private function fixture(): array
    {
        $capture = new CaptureRecord(UuidCodec::newV7(), 'repair-capture', hash('sha256', 'repair-capture'), 'SEMANTICS_RECONCILED', 'IN_PROGRESS', context: ['content_intent' => ['intent' => 'KNOWLEDGE_REPAIR']], revision: 12);
        $subject = UuidCodec::newV7();
        $claims = [];
        for ($index = 1; $index <= 5; $index++) {
            $claims[] = new KnowledgeClaim(UuidCodec::newV7(), 'repair:claim:' . $index, 'Contaminated claim ' . $index, 'fact', ['metadata' => ['subject_id' => $subject, 'facet' => 'history', 'scope' => 'entity']]);
        }
        $source = new Source(UuidCodec::newV7(), 'repair:source', 'Repair source', 'catalog', 'https://example.test/source', ['visibility' => 'PRIVATE'], true, 3);
        $evidence = new Evidence(UuidCodec::newV7(), $claims[0]->canonicalId, $source->canonicalId, 'supports', 'Original excerpt', null, true, 4);
        $claimRepository = new class($claims) implements KnowledgeRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
        $sourceRepository = new class($source) implements SourceRepository {
            public function __construct(private Source $item) {}
            public function findByCanonicalId(string $id): ?Source { return $id === $this->item->canonicalId ? $this->item : null; }
            public function findByStableKey(string $stableKey): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return [$this->item]; }
        };
        $evidenceRepository = new class($evidence) implements EvidenceRepository {
            public function __construct(private Evidence $item) {}
            public function findByCanonicalId(string $id): ?Evidence { return $id === $this->item->canonicalId ? $this->item : null; }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return $claimId === $this->item->claimId ? [] : []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return $sourceId === $this->item->sourceId ? [$this->item] : []; }
        };
        $validator = new CanonicalDependencyValidator($claimRepository, $sourceRepository, $evidenceRepository);
        $preview = new KnowledgeRepairPreviewService($claimRepository, $evidenceRepository);
        return [$capture, $claims, $source, $evidence, new CaptureBoundRepairPlanComposer($claimRepository, $preview, $validator)];
    }

    private function scope(CaptureRecord $capture, array $plan): array
    {
        return (new StagingAcceptanceScopeVerifier(
            static fn (): string => 'staging',
            'test-secret',
            static fn (array $scope, CaptureRecord $record, array $input, array $assets): bool => (new CaptureDependencyStagingAdmission())(false, $scope, $record, $input, $assets),
            can: static fn (): bool => true,
        ))->issueForCaptureDependencyPlan($capture, $plan);
    }
}
