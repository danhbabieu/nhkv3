<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\{AuthorityProposalCandidateBindingResolver, OperationScopedStagingGuard, StagingAcceptanceScopeVerifier};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use PHPUnit\Framework\TestCase;

final class AuthorityProposalCandidateBindingResolverTest extends TestCase
{
    public function test_legacy_proposal_recovers_the_single_approved_candidate_from_persisted_capture(): void
    {
        $capture = $this->capture([
            'update_candidates' => [$this->candidate('candidate-rename', 'RENAME')],
        ], ['candidate-rename']);
        $proposal = $this->proposal($capture, 'rename');

        $resolved = (new AuthorityProposalCandidateBindingResolver())->resolve($proposal, $capture);

        self::assertSame('candidate-rename', $resolved['candidate_id']);
        self::assertSame('RECOVERED_FROM_PERSISTED_CAPTURE', $resolved['status']);
    }

    public function test_ambiguous_candidates_fail_closed(): void
    {
        $capture = $this->capture([
            'update_candidates' => [
                $this->candidate('candidate-a', 'RENAME'),
                $this->candidate('candidate-b', 'RENAME'),
            ],
        ], ['candidate-a', 'candidate-b']);

        $this->expectExceptionMessage('STAGING_CANDIDATE_SCOPE_AMBIGUOUS');
        (new AuthorityProposalCandidateBindingResolver())->resolve($this->proposal($capture, 'rename'), $capture);
    }

    public function test_candidate_from_another_capture_does_not_bind(): void
    {
        $capture = $this->capture([
            'update_candidates' => [$this->candidate('candidate-other', 'RENAME')],
        ], ['candidate-other']);
        $proposal = $this->proposal($capture, 'rename', '01999999-9999-7999-8999-999999999996');

        $this->expectExceptionMessage('LEGACY_PROPOSAL_MISSING_CANDIDATE_BINDING');
        (new AuthorityProposalCandidateBindingResolver())->resolve($proposal, $capture);
    }

    public function test_plan_fingerprint_mismatch_does_not_bind(): void
    {
        $capture = $this->capture([
            'update_candidates' => [$this->candidate('candidate-rename', 'RENAME')],
        ], ['candidate-rename']);
        $proposal = $this->proposal($capture, 'rename');
        $proposal = new Proposal($proposal->id, $proposal->subjectId, $proposal->operation, array_replace($proposal->payload, [
            'project_build_audit' => ['capture_id' => $capture->captureId, 'plan_fingerprint' => str_repeat('b', 64)],
        ]), $proposal->contentFingerprint, $proposal->expectedRevision, $proposal->dependencyFingerprint, $proposal->state, idempotencyKey: $proposal->idempotencyKey, targetUuid: $proposal->targetUuid, entityType: $proposal->entityType);

        $this->expectExceptionMessage('STAGING_PLAN_SCOPE_MISMATCH');
        (new AuthorityProposalCandidateBindingResolver())->resolve($proposal, $capture);
    }

    /** @dataProvider authorityOperationProvider */
    public function test_recovered_binding_passes_the_same_operation_guard_for_rename_and_retire(string $operation): void
    {
        $capture = $this->capture(['update_candidates' => [$this->candidate('candidate-' . $operation, strtoupper($operation))]], ['candidate-' . $operation]);
        $proposal = $this->proposal($capture, $operation);
        $verifier = new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'scope-secret', static fn (): bool => true);
        $resolver = new AuthorityProposalCandidateBindingResolver();
        $guard = new OperationScopedStagingGuard(
            static fn (): string => 'staging',
            static fn (string $capability): bool => true,
            scopeVerifier: [$verifier, 'proposalFailureReason'],
            scopeResolver: static function (Proposal $actual) use ($capture, $resolver, $verifier): array {
                $binding = $resolver->resolve($actual, $capture);
                return $verifier->issueForAuthorityPlan($capture, $capture->context['authority_plan'], [$binding['candidate_id']]);
            },
        );

        $guard->assertAllowed($proposal);
        self::assertTrue(true);
    }

    /** @return iterable<string,array{string}> */
    public static function authorityOperationProvider(): iterable
    {
        yield 'rename' => ['rename'];
        yield 'retire' => ['retire'];
    }

    /** @param array<string,mixed> $plan @param list<string> $approvedIds */
    private function capture(array $plan, array $approvedIds): CaptureRecord
    {
        $captureId = '01999999-9999-7999-8999-999999999997';
        $plan['plan_fingerprint'] = str_repeat('a', 64);
        return new CaptureRecord($captureId, 'authority-binding', str_repeat('d', 64), 'AUTHORITY_APPLIED', 'APPLIED', context: [
            'purpose' => 'AUTHORITY',
            'authority_plan' => $plan,
            'plan_fingerprint' => str_repeat('a', 64),
            'authority_result' => ['approved_plan_fingerprint' => str_repeat('a', 64), 'approved_candidate_ids' => $approvedIds, 'result' => ['status' => 'APPLIED']],
        ]);
    }

    private function proposal(CaptureRecord $capture, string $operation, string $subject = '01999999-9999-7999-8999-999999999999'): Proposal
    {
        return new Proposal('01999999-9999-7999-8999-999999999998', $subject, $operation, [
            'project_build_audit' => ['capture_id' => $capture->captureId, 'plan_fingerprint' => str_repeat('a', 64)],
        ], str_repeat('b', 64), 1, str_repeat('c', 64), ProposalState::APPROVED, idempotencyKey: 'legacy-' . $operation, targetUuid: $subject, entityType: 'classification');
    }

    /** @return array<string,mixed> */
    private function candidate(string $id, string $action): array
    {
        return ['candidate_id' => $id, 'action' => $action, 'entity_type' => 'classification', 'canonical_uuid' => '01999999-9999-7999-8999-999999999999', 'expected_revision' => 1];
    }
}
