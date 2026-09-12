<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\GovernedCaptureContinuationService;
use NHK\Core\Application\Capture\CaptureOrchestrationBudget;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Semantic\ClaimReusePolicy;
use NHK\Core\Contracts\Governance\{AutomationPolicyStorage, GovernedLifecycle, VideoProposalReconciliationPort};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class GovernedCaptureContinuationServiceTest extends TestCase
{
    public function test_existing_capture_continuation_runs_governance_and_requires_explicit_approval(): void
    {
        $variant = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $variant, 'ingest', ['text' => 'Côn chữ U màu trắng.'], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'continuation:knowledge:0', entityType: 'knowledge');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposal->id)->willReturnOnConsecutiveCalls(['state' => 'draft', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'], ['state' => 'submitted', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->expects(self::once())->method('submit')->with($proposal->id)->willReturn($proposal);
        $governance->expects(self::never())->method('approve');
        $apply = static function (): array { throw new \LogicException('apply must not run before approval'); };
        $service = new GovernedCaptureContinuationService($governance, $apply, $this->policies(), static fn (string $capability): bool => true);

        $result = $service->execute('capture-1', 'continuation', ['subject_resolution' => ['resolved' => [['id' => $variant, 'type' => 'variant']]], 'interpretation' => ['user_claim_candidates' => [['text' => 'Côn chữ U màu trắng.', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]], 'observations' => []]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame(['GOVERNANCE_APPROVAL_REQUIRED'], $result['blockers']);
        self::assertSame($proposal->id, $result['writes'][0]['proposal_id']);
    }

    public function test_existing_capture_continuation_applies_only_after_governance_and_readback(): void
    {
        $proposalId = UuidCodec::newV7();
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn(['state' => 'approved', 'entity_type' => 'relation', 'operation' => 'relation_create', 'subject_id' => 'relation', 'payload' => [], 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency', 'revision' => 2]);
        $governance->expects(self::once())->method('eligibility')->with($proposalId)->willReturn(['ready' => true]);
        $applied = false;
        $service = new GovernedCaptureContinuationService($governance, static function (string $id) use (&$applied): array { $applied = true; return ['canonical_id' => $id, 'canonical_readback' => ['canonical_id' => $id, 'revision' => 1], 'idempotent' => false]; }, $this->policies(['relation']), static fn (string $capability): bool => true);

        $result = $service->execute('capture-1', 'continuation', [], ['proposal_ids' => [$proposalId]]);

        self::assertTrue($applied);
        self::assertSame('APPLIED', $result['status']);
        self::assertSame($proposalId, $result['writes'][0]['proposal_id']);
    }

    public function test_existing_supported_claim_is_reused_before_continuation_proposal_creation(): void
    {
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::never())->method('createFromArguments');
        $variant = '95873bfe-d978-4eda-a5a2-ce9ba79625df';
        $service = new GovernedCaptureContinuationService($governance, static fn (string $id): array => [], $this->policies(), static fn (string $capability): bool => true, new ClaimReusePolicy());

        $result = $service->execute('capture-355', 'addendum-configuration', [
            'subject_resolution' => ['resolved' => [['id' => $variant, 'type' => 'variant']]],
            'interpretation' => ['user_claim_candidates' => [[
                'text' => 'Cấu hình 10 côn 10 búa, chơi 2 bài nhạc.',
                'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            ]]],
            'retrieval' => ['selected_claims' => [[
                'claim_id' => '01a06d45-aa68-7d08-b6a0-7cccb84ae75b',
                'claim_revision' => 2,
                'text' => 'Một hiện vật được Bibelot & Co mô tả là Odo n°36, serial 4583, có 10 côn/tiges, 10 búa/marteaux và hai giai điệu.',
                'subject_id' => $variant,
                'scope' => 'variant',
                'provenance' => 'CATALOG_SUPPORTED',
                'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            ]]],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame([], $result['writes']);
        self::assertSame('01a06d45-aa68-7d08-b6a0-7cccb84ae75b', $result['reused_claims'][0]['claim_id']);
    }

    public function test_auto_publish_applies_new_capture_claim_and_reads_back_canonical_owner(): void
    {
        $variant = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $variant, 'ingest', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:auto:knowledge', entityType: 'knowledge');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::once())->method('submit')->with($proposal->id)->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposal->id)->willReturnOnConsecutiveCalls(['state' => 'draft', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'], ['state' => 'submitted', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->expects(self::once())->method('approve')->with($proposal->id, 'content', 'dependency', self::anything())->willReturn($proposal->transition(ProposalState::APPROVED, 'system'));
        $governance->expects(self::once())->method('eligibility')->with($proposal->id)->willReturn(['ready' => true]);
        $applied = [];
        $service = new GovernedCaptureContinuationService($governance, static function (string $id) use (&$applied): array {
            $applied[] = $id;
            return ['canonical_id' => 'claim-1', 'canonical_readback' => ['canonical_id' => 'claim-1', 'entity_type' => 'knowledge', 'active' => true, 'revision' => 1]];
        }, $this->policies(['knowledge'], ['knowledge' => 'AUTO_PUBLISH']), static fn (string $capability): bool => true);

        $result = $service->execute('capture-1', 'capture-1:semantic', [
            'subject_resolution' => ['resolved' => [['id' => $variant, 'type' => 'variant']]],
            'interpretation' => ['user_claim_candidates' => [['text' => 'Cấu hình 10 côn.', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]],
            'observations' => [],
        ]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame([$proposal->id], $applied);
        self::assertSame(['canonical_id' => 'claim-1', 'entity_type' => 'knowledge', 'active' => true, 'revision' => 1], $result['writes'][0]['canonical_readback']);
    }

    public function test_auto_publish_submits_video_proposal_from_capture_asset_without_duplicate_writer(): void
    {
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:auto:video', entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static fn (array $args): bool => ($args['entity_type'] ?? '') === 'video' && ($args['operation'] ?? '') === 'ingest'))->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal);
        $governance->method('review')->willReturn(['state' => 'draft', 'entity_type' => 'video', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('approve')->willReturn($proposal->transition(ProposalState::APPROVED, 'system'));
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $service = new GovernedCaptureContinuationService($governance, static function (string $id) use ($videoId): array { return ['canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId, 'entity_type' => 'video', 'active' => true, 'revision' => 1]]; }, $this->policies(['video'], ['video' => 'AUTO_PUBLISH']), static fn (string $capability): bool => true);

        $result = $service->execute('capture-1', 'capture-1:semantic', ['subject_resolution' => ['resolved' => []], 'interpretation' => [], 'observations' => [], 'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]]]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame($videoId, $result['writes'][0]['canonical_readback']['canonical_id']);
    }

    public function test_text_only_existing_capture_addendum_skips_unchanged_video_child_without_reentering_governance(): void
    {
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::never())->method('createFromArguments');
        $videoId = UuidCodec::newV7();
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (string $id): array => throw new \LogicException('unchanged Video must not apply'),
            $this->policies(['video']),
            static fn (string $capability): bool => true,
        );

        $result = $service->execute('capture-legacy', 'addendum-text', [
            'existing_capture_continuation' => true,
            'continuation_delta_text' => 'Bổ sung văn bản không liên quan đến Video.',
            'subject_resolution' => ['resolved' => []],
            'assets' => [[
                'kind' => 'video',
                'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]],
            ]],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame('SKIPPED_UNCHANGED', $result['writes'][0]['status']);
        self::assertSame('VIDEO_CHILD_UNCHANGED_ON_TEXT_ADDENDUM', $result['blockers'][0]);
    }

    public function test_invalid_hydrated_video_subject_uses_governed_replacement_boundary(): void
    {
        $videoId = UuidCodec::newV7();
        $proposalId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, 'video', 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'legacy-video', entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn(['state' => 'approved', 'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => 'video', 'payload' => ['canonical_id' => $videoId], 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $repair = $this->createMock(VideoProposalReconciliationPort::class);
        $repair->expects(self::once())->method('reconcile')->with($proposalId)->willReturn(['status' => 'REBUILT_AND_APPLIED', 'replaced_proposal_id' => UuidCodec::newV7(), 'canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId, 'active' => true]]);
        $service = new GovernedCaptureContinuationService($governance, static fn (string $id): array => [], $this->policies(['video']), static fn (string $capability): bool => true, null, null, $repair);

        $result = $service->execute('capture-legacy', 'legacy-video', [
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]],
        ]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame($videoId, $result['writes'][0]['canonical_readback']['canonical_id']);
        self::assertSame('REBUILT_AND_APPLIED', $result['writes'][0]['repair']['status']);
    }

    public function test_legacy_video_skip_reenters_when_dependency_fingerprint_changes(): void
    {
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'legacy-progress', entityType: 'video');
        $state = ['revision' => 1];
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->method('createFromArguments')->willReturn($proposal);
        $governance->method('review')->willReturn(['state' => 'approved', 'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'payload' => ['canonical_id' => $videoId], 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $service = new GovernedCaptureContinuationService($governance, static fn (string $id): array => ['canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId, 'active' => true]], $this->policies(['video'], ['video' => 'AUTO_PUBLISH']), static fn (string $capability): bool => true, null, null, null, static function (array $plan) use (&$state): array { return ['source' => ['source-1', $state['revision'], true]]; });
        $context = ['existing_capture_continuation' => true, 'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]]];
        $first = $service->execute('legacy', 'addendum-1', $context);
        self::assertSame('SKIPPED_UNCHANGED', $first['writes'][0]['status']);
        $context['prior_diagnostics'] = ['semantic_write_back' => ['video_children' => $first['video_children']]];
        $second = $service->execute('legacy', 'addendum-2', $context);
        self::assertSame('SKIPPED_UNCHANGED', $second['writes'][0]['status']);
        $state['revision'] = 2;
        $third = $service->execute('legacy', 'addendum-3', $context);
        self::assertSame('APPLIED', $third['status']);
        self::assertNotSame('SKIPPED_UNCHANGED', $third['writes'][0]['status']);
    }

    public function test_video_child_fingerprint_ignores_timestamps_and_evidence_order_but_tracks_semantic_revisions(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $method = new \ReflectionMethod($service, 'videoPlanFingerprint');
        $method->setAccessible(true);
        $video = UuidCodec::newV7();
        $plan = ['capture_video_provenance' => ['relation' => ['target_type' => 'variant', 'target_uuid' => $video], 'dependencies' => [['payload' => ['metadata' => ['platform' => 'youtube', 'external_video_id' => 'abc']]]]]];
        $base = ['subject' => ['id' => $video, 'revision' => 3], 'source' => ['source-1', 2, true], 'claim' => ['claim-1', 4, true], 'evidence' => [['e-2', 1, 'source-1', true], ['e-1', 1, 'source-1', true]], 'proposal' => ['p-1', 2, 'approved']];
        $same = $base; $same['evidence'] = array_reverse($same['evidence']); $same['fetched_at'] = 'different';
        self::assertSame($method->invoke($service, $plan, ['video_dependency_fingerprint' => $base]), $method->invoke($service, $plan, ['video_dependency_fingerprint' => $same]));
        $changed = $base; $changed['evidence'][0][1] = 2;
        self::assertNotSame($method->invoke($service, $plan, ['video_dependency_fingerprint' => $base]), $method->invoke($service, $plan, ['video_dependency_fingerprint' => $changed]));
        $changedSubject = $base; $changedSubject['subject']['revision'] = 4;
        self::assertNotSame($method->invoke($service, $plan, ['video_dependency_fingerprint' => $base]), $method->invoke($service, $plan, ['video_dependency_fingerprint' => $changedSubject]));
    }

    public function test_budget_stops_before_next_expensive_phase(): void
    {
        $now = 100.0;
        $budget = new CaptureOrchestrationBudget(10, static function () use (&$now): float { return $now; });
        $budget->begin(); $now = 110.0;
        $this->expectException(\NHK\Core\Application\Capture\CaptureOrchestrationBudgetExceeded::class);
        $budget->check('NEXT_PHASE');
    }

    public function test_typed_binding_failure_is_blocked_even_when_exception_wording_changes(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $method = new \ReflectionMethod($service, 'classifiedFailure');
        $method->setAccessible(true);
        $result = $method->invoke($service, ['entity_type' => 'video'], new \NHK\Core\Governance\Exception\ProposalSubjectBindingInvalid('changed diagnostic wording'));
        self::assertSame('SYSTEM_BLOCKED', $result['status']);
    }

    private function policies(array $types = ['knowledge'], array $stored = []): GovernanceAutomationPolicyResolver
    {
        return new GovernanceAutomationPolicyResolver($types, new class($stored) implements AutomationPolicyStorage {
            public function __construct(private array $stored) {}
            public function read(): array { return $this->stored; }
            public function write(array $policies): void {}
        });
    }
}
