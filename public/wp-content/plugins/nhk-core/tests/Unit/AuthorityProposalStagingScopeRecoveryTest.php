<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\OperationScopedStagingGuard;
use NHK\Core\Application\Governance\StagingAcceptanceScopeVerifier;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use PHPUnit\Framework\TestCase;

final class AuthorityProposalStagingScopeRecoveryTest extends TestCase
{
    public function test_missing_authority_scope_is_resolved_from_the_persisted_capture_context(): void
    {
        $proposal = $this->proposal('classification', 'rename', 'candidate-rename');
        $scope = $this->scope($proposal);
        $guard = new OperationScopedStagingGuard(
            static fn (): string => 'staging',
            static fn (string $capability): bool => true,
            scopeVerifier: static fn (array $resolved, Proposal $actual): bool => $resolved === $scope && $actual->id === $proposal->id,
            scopeResolver: static fn (Proposal $actual): ?array => $actual->id === $proposal->id ? $scope : null,
        );

        $guard->assertAllowed($proposal);
        self::assertTrue(true);
    }

    public function test_unresolvable_authority_scope_remains_fail_closed(): void
    {
        $proposal = $this->proposal('brand', 'update', 'candidate-update');
        $guard = new OperationScopedStagingGuard(static fn (): string => 'staging', static fn (string $capability): bool => true);

        $this->expectExceptionMessage('STAGING_SCOPE_REQUIRED');
        $guard->assertAllowed($proposal);
    }

    public function test_classification_rename_and_generic_authority_update_use_the_same_registry_scope(): void
    {
        foreach ([['classification', 'rename'], ['brand', 'update']] as [$type, $operation]) {
            $proposal = $this->proposal($type, $operation, 'candidate-' . $type . '-' . $operation);
            $capture = $this->capture($proposal);
            $verifier = new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'scope-secret', static fn (): bool => true);
            $guard = $this->guardFromCapture($capture, $verifier);

            $guard->assertAllowed($proposal);
            self::assertTrue(true);
        }
    }

    public function test_stale_revision_and_invalid_scope_do_not_get_recovered(): void
    {
        $proposal = $this->proposal('classification', 'rename', 'candidate-stale');
        $capture = $this->capture($proposal, expectedRevision: 1);
        $verifier = new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'scope-secret', static fn (): bool => true);
        $stale = new Proposal($proposal->id, $proposal->subjectId, $proposal->operation, $proposal->payload, $proposal->contentFingerprint, 2, $proposal->dependencyFingerprint, $proposal->state, idempotencyKey: $proposal->idempotencyKey, targetUuid: $proposal->targetUuid, entityType: $proposal->entityType);
        $guard = $this->guardFromCapture($capture, $verifier);
        try {
            $guard->assertAllowed($stale);
            self::fail('A stale expected revision must remain blocked.');
        } catch (\RuntimeException $error) {
            self::assertSame('STAGING_CANDIDATE_SCOPE_MISMATCH', $error->getMessage());
        }

        $invalidScope = $proposal->payload + ['staging_acceptance' => ['approved' => true, 'writer' => 'direct_writer']];
        $invalid = new Proposal($proposal->id, $proposal->subjectId, $proposal->operation, $invalidScope, $proposal->contentFingerprint, $proposal->expectedRevision, $proposal->dependencyFingerprint, $proposal->state, idempotencyKey: $proposal->idempotencyKey, targetUuid: $proposal->targetUuid, entityType: $proposal->entityType);
        $this->expectExceptionMessage('STAGING_SCOPE_NOT_APPROVED');
        $this->guardFromCapture($capture, $verifier)->assertAllowed($invalid);
    }

    /** @return array<string,mixed> */
    private function scope(Proposal $proposal): array
    {
        return [
            'approved' => true,
            'environment' => 'staging',
            'writer' => 'canonical_governed',
            'operation_family' => 'governed_authority_plan',
            'capture_id' => $proposal->payload['project_build_audit']['capture_id'],
            'plan_fingerprint' => $proposal->payload['project_build_audit']['plan_fingerprint'],
            'candidate_bindings' => [[
                'candidate_id' => $proposal->payload['candidate_id'],
                'entity_type' => $proposal->entityType,
                'operation' => $proposal->operation,
                'subject_id' => $proposal->subjectId,
                'target_uuid' => $proposal->targetUuid,
                'expected_revision' => $proposal->expectedRevision,
            ]],
        ];
    }

    private function capture(Proposal $proposal, int $expectedRevision = 1): CaptureRecord
    {
        return new CaptureRecord(
            $proposal->payload['project_build_audit']['capture_id'],
            'authority-scope-' . $proposal->payload['candidate_id'],
            str_repeat('d', 64),
            'AUTHORITY_PLANNED',
            'PLANNED',
            null,
            null,
            [],
            [
                'purpose' => 'AUTHORITY',
                'planning_input' => ['purpose' => 'AUTHORITY', 'authority_intent' => ['mode' => 'PLAN']],
                'authority_plan' => [
                    'plan_fingerprint' => $proposal->payload['project_build_audit']['plan_fingerprint'],
                    'update_candidates' => [[
                        'candidate_id' => $proposal->payload['candidate_id'],
                        'action' => strtoupper($proposal->operation),
                        'entity_type' => $proposal->entityType,
                        'canonical_uuid' => $proposal->subjectId,
                        'target_uuid' => $proposal->targetUuid,
                        'expected_revision' => $expectedRevision,
                    ]],
                ],
            ],
            [],
            [],
        );
    }

    private function guardFromCapture(CaptureRecord $capture, StagingAcceptanceScopeVerifier $verifier): OperationScopedStagingGuard
    {
        return new OperationScopedStagingGuard(
            static fn (): string => 'staging',
            static fn (string $capability): bool => true,
            scopeVerifier: [$verifier, 'proposalFailureReason'],
            scopeResolver: static function (Proposal $proposal) use ($capture, $verifier): ?array {
                return $verifier->issueForAuthorityPlan($capture, $capture->context['authority_plan'], [(string) $proposal->payload['candidate_id']]);
            },
        );
    }

    private function proposal(string $entityType, string $operation, string $candidateId): Proposal
    {
        $target = '01999999-9999-7999-8999-999999999999';
        return new Proposal(
            '01999999-9999-7999-8999-999999999998',
            $target,
            $operation,
            [
                'candidate_id' => $candidateId,
                'project_build_audit' => [
                    'capture_id' => '01999999-9999-7999-8999-999999999997',
                    'plan_fingerprint' => str_repeat('a', 64),
                ],
            ],
            str_repeat('b', 64),
            $operation === 'create' ? null : 1,
            str_repeat('c', 64),
            ProposalState::APPROVED,
            idempotencyKey: 'authority:' . $candidateId,
            targetUuid: $operation === 'create' ? null : $target,
            entityType: $entityType,
        );
    }
}
