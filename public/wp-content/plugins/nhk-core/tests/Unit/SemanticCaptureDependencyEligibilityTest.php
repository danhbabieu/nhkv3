<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\{CaptureDependencyStagingAdmission, ProposalEligibilityService, StagingAcceptanceScopeVerifier};
use NHK\Core\Contracts\Governance\{DependencyRepository, EligibilityReader, ProposalRepository};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\{DependencyGraph, Proposal, ProposalState};
use NHK\Core\Shared\{TestRuntimeIdentityPolicy};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class SemanticCaptureDependencyEligibilityTest extends TestCase
{
    /** @dataProvider dependencyTypeProvider */
    public function test_minimal_capture_bound_source_knowledge_and_evidence_proposals_are_eligible(string $entityType): void
    {
        [$capture, $proposal, $scope, $verifier] = $this->fixture($entityType);
        $result = $this->eligibility($proposal, $verifier, static fn (): CaptureRecord => $capture)->check($proposal->id);

        self::assertTrue(TestRuntimeIdentityPolicy::evaluate([
            'environment' => 'staging',
            'database' => TestRuntimeIdentityPolicy::DATABASE,
            'site_url' => TestRuntimeIdentityPolicy::SITE_URL,
            'project' => TestRuntimeIdentityPolicy::PROJECT,
            'runtime_identity' => TestRuntimeIdentityPolicy::PROJECT,
        ])['allowed']);
        self::assertSame('staging', $scope['environment']);
        self::assertSame('PROJECT_BUILD', $scope['semantic_write_policy']);
        self::assertSame($capture->requestFingerprint, $scope['capture_fingerprint']);
        self::assertSame($capture->revision, $scope['capture_revision']);
        self::assertArrayNotHasKey('capture_fingerprint', $proposal->payload);
        self::assertArrayNotHasKey('capture_revision', $proposal->payload);
        self::assertTrue($result->ready, json_encode($result->reasons, JSON_THROW_ON_ERROR));
        self::assertNotContains('STAGING_SCOPE_REQUIRED', $result->reasons);
        self::assertNotContains('STAGING_CAPTURE_REQUIRED', $result->reasons);
        self::assertNotContains('STAGING_DEPENDENCY_SCOPE_MISMATCH', $result->reasons);
        self::assertNull($verifier->proposalFailureReason($scope, $proposal));
    }

    /** @dataProvider dependencyTypeProvider */
    public function test_missing_capture_id_remains_fail_closed(string $entityType): void
    {
        [, $proposal, $scope, $verifier] = $this->fixture($entityType);
        $payload = $proposal->payload;
        unset($payload['capture_id']);
        $missing = $this->rebuild($proposal, $payload);
        self::assertSame('STAGING_CAPTURE_SCOPE_MISMATCH', $verifier->proposalFailureReason($scope, $missing));
    }

    /** @dataProvider dependencyTypeProvider */
    public function test_wrong_capture_id_remains_non_transferable(string $entityType): void
    {
        [, $proposal, $scope, $verifier] = $this->fixture($entityType);
        $wrong = $proposal->payload;
        $wrong['capture_id'] = UuidCodec::newV7();
        self::assertSame('STAGING_CAPTURE_SCOPE_MISMATCH', $verifier->proposalFailureReason($scope, $this->rebuild($proposal, $wrong)));
    }

    /** @dataProvider dependencyTypeProvider */
    public function test_nonexistent_capture_remains_fail_closed_at_scope_resolution(string $entityType): void
    {
        [, $proposal, $scope, $verifier] = $this->fixture($entityType);
        $result = $this->eligibility($proposal, $verifier, static fn (): ?CaptureRecord => null)->check($proposal->id);

        self::assertFalse($result->ready);
        self::assertContains('STAGING_CAPTURE_NOT_FOUND', $result->reasons);
    }

    /** @dataProvider dependencyTypeProvider */
    public function test_tampered_signed_scope_remains_fail_closed(string $entityType): void
    {
        [, $proposal, $scope, $verifier] = $this->fixture($entityType);
        $tampered = $scope;
        $tampered['capture_revision']++;
        self::assertSame('STAGING_SCOPE_NOT_APPROVED', $verifier->proposalFailureReason($tampered, $proposal));
    }

    public function test_wrong_entity_binding_remains_fail_closed(): void
    {
        [, $proposal, $scope, $verifier] = $this->fixture('source');
        $wrong = new Proposal($proposal->id, $proposal->subjectId, $proposal->operation, $proposal->payload, $proposal->contentFingerprint, $proposal->expectedRevision, $proposal->dependencyFingerprint, $proposal->state, idempotencyKey: $proposal->idempotencyKey, targetUuid: $proposal->targetUuid, entityType: 'knowledge');

        self::assertSame('STAGING_OPERATION_SCOPE_MISMATCH', $verifier->proposalFailureReason($scope, $wrong));
    }

    /** @dataProvider dependencyTypeProvider */
    public function test_semantic_payload_fingerprint_mismatch_remains_fail_closed(string $entityType): void
    {
        [, $proposal, $scope, $verifier] = $this->fixture($entityType);
        $tampered = $proposal->payload;
        $tampered['semantic_value'] = 'tampered';
        self::assertSame('STAGING_DEPENDENCY_PAYLOAD_MISMATCH', $verifier->proposalFailureReason($scope, $this->rebuild($proposal, $tampered)));
    }

    /** @dataProvider dependencyTypeProvider */
    public function test_optional_duplicated_capture_metadata_cannot_be_tampered(string $entityType): void
    {
        [, $proposal, $scope, $verifier] = $this->fixture($entityType);
        $withMetadata = $proposal->payload + [
            'capture_fingerprint' => $scope['capture_fingerprint'],
            'capture_revision' => $scope['capture_revision'],
        ];
        $tampered = $withMetadata;
        $tampered['capture_revision']++;

        self::assertSame('STAGING_CAPTURE_REVISION_MISMATCH', $verifier->proposalFailureReason($scope, $this->rebuild($proposal, $tampered)));
    }

    public function test_invalid_test_runtime_identity_remains_fail_closed(): void
    {
        $decision = TestRuntimeIdentityPolicy::evaluate([
            'environment' => 'staging',
            'database' => 'wrong_database',
            'site_url' => TestRuntimeIdentityPolicy::SITE_URL,
            'project' => TestRuntimeIdentityPolicy::PROJECT,
            'runtime_identity' => TestRuntimeIdentityPolicy::PROJECT,
        ]);

        self::assertFalse($decision['allowed']);
        self::assertSame('TEST_RUNTIME_DATABASE_MISMATCH', $decision['reason']);
    }

    public function test_wrong_expected_revision_remains_fail_closed_for_retirement(): void
    {
        [, $proposal, $scope, $verifier] = $this->retirementFixture('source');
        $wrong = new Proposal(
            $proposal->id,
            $proposal->subjectId,
            $proposal->operation,
            $proposal->payload,
            $proposal->contentFingerprint,
            2,
            $proposal->dependencyFingerprint,
            $proposal->state,
            idempotencyKey: $proposal->idempotencyKey,
            targetUuid: $proposal->targetUuid,
            entityType: $proposal->entityType,
        );

        self::assertSame('STAGING_DEPENDENCY_SCOPE_MISMATCH', $verifier->proposalFailureReason($scope, $wrong));
    }

    /** @dataProvider retirementTypeProvider */
    public function test_minimal_capture_bound_retirement_proposals_are_admitted_and_eligible(string $entityType, string $expectedFamily): void
    {
        [$capture, $proposal, $scope, $verifier] = $this->retirementFixture($entityType);
        $result = $this->eligibility($proposal, $verifier, static fn (): CaptureRecord => $capture)->check($proposal->id);

        self::assertSame(['capture_id'], array_keys($proposal->payload));
        self::assertSame($proposal->subjectId, $scope['subject_id']);
        self::assertSame(1, $scope['expected_revision']);
        self::assertSame($expectedFamily, $scope['operation_family']);
        self::assertTrue($result->ready, json_encode($result->reasons, JSON_THROW_ON_ERROR));
        self::assertNotContains('STAGING_SCOPE_NOT_ADMITTED', $result->reasons);
        self::assertNotContains('STAGING_SCOPE_REQUIRED', $result->reasons);
        self::assertNotContains('STAGING_CAPTURE_REQUIRED', $result->reasons);
        self::assertSame([], $result->reasons);
        self::assertNull($verifier->proposalFailureReason($scope, $proposal));
    }

    /** @dataProvider retirementTypeProvider */
    public function test_minimal_capture_bound_reactivation_proposals_are_admitted_with_existing_revision(string $entityType, string $expectedFamily): void
    {
        [$capture, $proposal, $scope, $verifier] = $this->retirementFixture($entityType, 'reactivate', 1);
        $result = $this->eligibility($proposal, $verifier, static fn (): CaptureRecord => $capture)->check($proposal->id);

        self::assertGreaterThanOrEqual(1, $scope['expected_revision']);
        self::assertSame($expectedFamily, $scope['operation_family']);
        self::assertTrue($result->ready, json_encode($result->reasons, JSON_THROW_ON_ERROR));
        self::assertSame([], $result->reasons);
        self::assertNull($verifier->proposalFailureReason($scope, $proposal));
    }

    /** @return iterable<string,array{0:string}> */
    public static function dependencyTypeProvider(): iterable
    {
        yield 'source' => ['source'];
        yield 'knowledge' => ['knowledge'];
        yield 'evidence' => ['evidence'];
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function retirementTypeProvider(): iterable
    {
        yield 'source' => ['source', 'source_evidence_reconciliation'];
        yield 'evidence' => ['evidence', 'source_evidence_reconciliation'];
        yield 'knowledge' => ['knowledge', 'knowledge_delta'];
    }

    /** @return array{0:CaptureRecord,1:Proposal,2:array<string,mixed>,3:StagingAcceptanceScopeVerifier} */
    private function fixture(string $entityType): array
    {
        $captureId = UuidCodec::newV7();
        $capture = new CaptureRecord(
            $captureId,
            'semantic-dependency-' . $entityType,
            hash('sha256', 'capture-' . $entityType),
            'SEMANTICS_RECONCILED',
            'IN_PROGRESS',
            context: ['content_intent' => ['intent' => 'KNOWLEDGE_DELTA']],
            revision: 7,
        );
        $payload = match ($entityType) {
            'source' => ['capture_id' => $captureId, 'stable_key' => 'test:source:' . $captureId, 'title' => 'A source', 'source_type' => 'catalog', 'locator' => 'https://example.test/source', 'semantic_value' => 'source'],
            'knowledge' => ['capture_id' => $captureId, 'stable_key' => 'test:knowledge:' . $captureId, 'text' => 'A claim', 'claim_type' => 'fact', 'semantic_value' => 'knowledge'],
            default => ['capture_id' => $captureId, 'claim_uuid' => UuidCodec::newV7(), 'source_uuid' => UuidCodec::newV7(), 'excerpt' => 'Evidence', 'relation' => 'supports', 'semantic_value' => 'evidence'],
        };
        $plan = [
            'entity_type' => $entityType,
            'operation' => 'ingest',
            'subject_id' => $entityType . '-subject',
            'idempotency_key' => 'semantic-dependency-' . $entityType . '-' . $captureId,
            'payload' => $payload,
        ];
        $verifier = new StagingAcceptanceScopeVerifier(
            static fn (): string => 'staging',
            'test-secret',
            static fn (array $scope, CaptureRecord $record, array $input, array $assets): bool => (new CaptureDependencyStagingAdmission())(false, $scope, $record, $input, $assets),
            can: static fn (): bool => true,
        );
        $scope = $verifier->issueForCaptureDependencyPlan($capture, $plan);
        $proposal = new Proposal(
            UuidCodec::newV7(),
            $plan['subject_id'],
            'ingest',
            $payload,
            'semantic-content',
            null,
            'semantic-dependency',
            ProposalState::APPROVED,
            idempotencyKey: $plan['idempotency_key'],
            entityType: $entityType,
        );

        return [$capture, $proposal, $scope, $verifier];
    }

    /** @return array{0:CaptureRecord,1:Proposal,2:array<string,mixed>,3:StagingAcceptanceScopeVerifier} */
    private function retirementFixture(string $entityType, string $operation = 'retire', int $expectedRevision = 1): array
    {
        $captureId = UuidCodec::newV7();
        $subjectId = UuidCodec::newV7();
        $capture = new CaptureRecord(
            $captureId,
            'semantic-retirement-' . $entityType,
            hash('sha256', 'capture-retirement-' . $entityType),
            'SEMANTICS_RECONCILED',
            'IN_PROGRESS',
            context: ['content_intent' => ['intent' => 'KNOWLEDGE_DELTA']],
            revision: 7,
        );
        $payload = ['capture_id' => $captureId];
        $plan = [
            'entity_type' => $entityType,
            'operation' => $operation,
            'subject_id' => $subjectId,
            'expected_revision' => $expectedRevision,
            'idempotency_key' => 'semantic-' . $operation . '-' . $entityType . '-' . $captureId,
            'payload' => $payload,
        ];
        $admission = new CaptureDependencyStagingAdmission();
        $verifier = new StagingAcceptanceScopeVerifier(
            static fn (): string => 'staging',
            'test-secret',
            static function (array $scope, CaptureRecord $record, array $input, array $assets) use ($admission): bool {
                return $admission(false, $scope, $record, $input, $assets);
            },
            can: static fn (): bool => true,
        );
        $scope = $verifier->issueForCaptureDependencyPlan($capture, $plan);
        $proposal = new Proposal(
            UuidCodec::newV7(),
            $subjectId,
            $operation,
            $payload,
            'semantic-retirement-content',
            $expectedRevision,
            'semantic-retirement-dependency',
            ProposalState::APPROVED,
            idempotencyKey: $plan['idempotency_key'],
            targetUuid: $subjectId,
            entityType: $entityType,
        );

        return [$capture, $proposal, $scope, $verifier];
    }

    private function eligibility(Proposal $proposal, StagingAcceptanceScopeVerifier $verifier, callable $captureResolver): ProposalEligibilityService
    {
        $repository = new class($proposal) implements ProposalRepository {
            public function __construct(private Proposal $proposal) {}
            public function create(Proposal $proposal): Proposal { return $proposal; }
            public function find(string $id): ?Proposal { return $id === $this->proposal->id ? $this->proposal : null; }
            public function findByIdempotencyKey(string $key): ?Proposal { return null; }
            public function save(Proposal $proposal): Proposal { return $this->proposal = $proposal; }
            public function findForUpdate(string $id): ?Proposal { return $this->find($id); }
            public function recordApproval(Proposal $proposal, string $actor): void {}
            public function latestApproval(string $proposalId): ?array { return ['proposal_revision' => $this->proposal->revision, 'fingerprint' => hex2bin($this->proposal->bindingFingerprint())]; }
            public function findLatestVideoIngest(string $videoId): ?Proposal { return null; }
        };
        $reader = new class implements EligibilityReader {
            public function isApplied(string $dependencyUuid): bool { return true; }
            public function targetRevision(string $targetUuid): ?int { return 1; }
            public function targetExists(string $targetUuid): bool { return true; }
        };
        $service = new ProposalEligibilityService($repository, new DependencyGraph(new class implements DependencyRepository {
            public function directDependencies(string $proposalId): array { return []; }
            public function add(string $proposalId, string $dependencyUuid): void {}
        }), $reader);
        $service->setStagingScopeResolver(static function (Proposal $checked) use ($captureResolver, $verifier): array {
            $capture = $captureResolver();
            if (!$capture instanceof CaptureRecord) throw new \RuntimeException('STAGING_CAPTURE_NOT_FOUND');
            return $verifier->issueForCaptureDependencyPlan($capture, [
                'entity_type' => $checked->entityType,
                'operation' => $checked->operation,
                'subject_id' => $checked->subjectId,
                'target_uuid' => $checked->targetUuid,
                'expected_revision' => $checked->expectedRevision,
                'idempotency_key' => $checked->idempotencyKey,
                'payload' => $checked->payload,
            ]);
        });
        $service->setStagingScopeVerifier(static function (Proposal $checked) use ($captureResolver, $verifier): bool|string {
            $capture = $captureResolver();
            if (!$capture instanceof CaptureRecord) return 'STAGING_CAPTURE_NOT_FOUND';
            $scope = $verifier->issueForCaptureDependencyPlan($capture, [
                'entity_type' => $checked->entityType,
                'operation' => $checked->operation,
                'subject_id' => $checked->subjectId,
                'target_uuid' => $checked->targetUuid,
                'expected_revision' => $checked->expectedRevision,
                'idempotency_key' => $checked->idempotencyKey,
                'payload' => $checked->payload,
            ]);
            return $verifier->proposalFailureReason($scope, $checked) ?? true;
        });
        return $service;
    }

    private function rebuild(Proposal $proposal, array $payload): Proposal
    {
        return new Proposal($proposal->id, $proposal->subjectId, $proposal->operation, $payload, $proposal->contentFingerprint, $proposal->expectedRevision, $proposal->dependencyFingerprint, $proposal->state, idempotencyKey: $proposal->idempotencyKey, targetUuid: $proposal->targetUuid, entityType: $proposal->entityType);
    }
}
