<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\GovernedCaptureContinuationService;
use NHK\Core\Application\Capture\CaptureOrchestrationBudget;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Semantic\ClaimReusePolicy;
use NHK\Core\Application\Video\{VideoEditorialGenerator, VideoEditorialResumePlanner, VideoSeoProjection};
use NHK\Core\Contracts\Governance\{AutomationPolicyStorage, GovernedLifecycle, VideoProposalReconciliationPort};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class GovernedCaptureContinuationServiceTest extends TestCase
{
    public function test_ordinary_image_article_with_no_semantic_delta_skips_semantic_mutation(): void
    {
        $subject = UuidCodec::newV7();
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::never())->method('createFromArguments');
        $service = new GovernedCaptureContinuationService($governance, static fn (): array => [], $this->policies(), static fn (): bool => true);

        $result = $service->execute('capture-573-fixture', 'resume-1', [
            'content_intent' => [
                'intent' => 'IMAGE_ARTICLE',
                'source' => 'CAPTURE',
                'semantic_delta' => ['status' => 'NONE'],
            ],
            'article_id' => 573,
            'article_endpoint_key' => '1:573',
            'subject_resolution' => ['primary' => ['id' => $subject, 'type' => 'classification'], 'resolved' => [['id' => $subject, 'type' => 'classification']]],
            'interpretation' => ['user_claim_candidates' => [['text' => 'Mô tả biên tập về hiện vật.', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]],
        ]);

        self::assertSame('NOT_REQUIRED', $result['requirements']['semantic_delta']['applicability']);
        self::assertSame('SKIPPED', $result['requirements']['semantic_delta']['state']);
        self::assertSame([], $result['plans']);
        self::assertNotContains('wp_post --about--> subject', $this->relationTypes($result));
    }

    public function test_explicit_knowledge_delta_retains_governed_proposal_approval_and_readback(): void
    {
        $subject = UuidCodec::newV7();
        $knowledgeProposal = new Proposal(UuidCodec::newV7(), $subject, 'ingest', [], 'knowledge-content', null, 'knowledge-dependency', ProposalState::DRAFT, entityType: 'knowledge');
        $relationProposal = new Proposal(UuidCodec::newV7(), 'relation', 'relation_create', [], 'relation-content', null, 'relation-dependency', ProposalState::DRAFT, entityType: 'relation');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::exactly(2))->method('createFromArguments')->willReturnOnConsecutiveCalls($knowledgeProposal, $relationProposal);
        $governance->expects(self::exactly(4))->method('review')->willReturnOnConsecutiveCalls(
            ['state' => 'draft', 'entity_type' => 'knowledge', 'content_fingerprint' => 'knowledge-content', 'dependency_fingerprint' => 'knowledge-dependency'],
            ['state' => 'submitted', 'entity_type' => 'knowledge', 'content_fingerprint' => 'knowledge-content', 'dependency_fingerprint' => 'knowledge-dependency'],
            ['state' => 'draft', 'entity_type' => 'relation', 'content_fingerprint' => 'relation-content', 'dependency_fingerprint' => 'relation-dependency'],
            ['state' => 'submitted', 'entity_type' => 'relation', 'content_fingerprint' => 'relation-content', 'dependency_fingerprint' => 'relation-dependency'],
        );
        $governance->expects(self::exactly(2))->method('submit')->willReturnOnConsecutiveCalls($knowledgeProposal->transition(ProposalState::SUBMITTED), $relationProposal->transition(ProposalState::SUBMITTED));
        $governance->expects(self::exactly(2))->method('approve')->willReturnOnConsecutiveCalls($knowledgeProposal->transition(ProposalState::APPROVED, 'system'), $relationProposal->transition(ProposalState::APPROVED, 'system'));
        $governance->expects(self::exactly(2))->method('eligibility')->willReturn(['ready' => true]);
        $applied = 0;
        $service = new GovernedCaptureContinuationService($governance, static function () use (&$applied): array {
            ++$applied;
            $canonicalId = $applied === 1 ? UuidCodec::newV7() : UuidCodec::newV7();
            return ['canonical_id' => $canonicalId, 'canonical_readback' => ['canonical_id' => $canonicalId, 'active' => true, 'revision' => 1]];
        }, $this->policies(['knowledge', 'relation'], ['knowledge' => 'AUTO_PUBLISH', 'relation' => 'AUTO_PUBLISH']), static fn (): bool => true);

        $result = $service->execute('capture-knowledge-delta', 'resume-knowledge-delta', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA', 'source' => 'CAPTURE', 'semantic_delta' => ['status' => 'REQUIRED']],
            'subject_resolution' => ['primary' => ['id' => $subject, 'type' => 'variant', 'revision' => 2], 'resolved' => [['id' => $subject, 'type' => 'variant', 'revision' => 2]]],
            'continuation_delta_text' => 'Bổ sung một claim có scope variant.',
        ]);

        self::assertSame('REQUIRED', $result['requirements']['semantic_delta']['applicability']);
        self::assertSame('VERIFIED', $result['requirements']['semantic_delta']['state']);
        self::assertSame(['PROPOSAL', 'SUBMIT', 'APPROVE', 'ELIGIBILITY', 'CONTROLLED_APPLY'], $result['governance']['lifecycle']);
        self::assertCount(2, $result['writes']);
        self::assertSame('APPLIED', $result['status']);
    }

    public function test_mixed_approved_semantic_branch_retains_governed_apply_and_readback(): void
    {
        $subject = UuidCodec::newV7();
        $proposalId = UuidCodec::newV7();
        $canonicalId = UuidCodec::newV7();
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn([
            'state' => 'approved', 'entity_type' => 'knowledge', 'operation' => 'update', 'subject_id' => $subject,
            'expected_revision' => 2, 'content_fingerprint' => 'mixed-content', 'dependency_fingerprint' => 'mixed-dependency', 'revision' => 1,
        ]);
        $governance->expects(self::once())->method('eligibility')->with($proposalId)->willReturn(['ready' => true]);
        $service = new GovernedCaptureContinuationService($governance, static fn (string $id): array => ['canonical_id' => $canonicalId, 'canonical_readback' => ['canonical_id' => $canonicalId, 'active' => true, 'revision' => 3]], $this->policies(['knowledge'], ['knowledge' => 'AUTO_PUBLISH']), static fn (): bool => true);

        $result = $service->execute('capture-mixed', 'resume-mixed', [
            'purpose' => 'MIXED',
            'content_intent' => ['intent' => 'MIXED', 'source' => 'CAPTURE', 'semantic_delta' => ['status' => 'REQUIRED', 'approved' => true]],
            'subject_resolution' => ['primary' => ['id' => $subject, 'type' => 'variant', 'revision' => 2], 'resolved' => [['id' => $subject, 'type' => 'variant', 'revision' => 2]]],
        ], ['proposal_ids' => [$proposalId]]);

        self::assertSame('REQUIRED', $result['requirements']['semantic_delta']['applicability']);
        self::assertSame('VERIFIED', $result['requirements']['semantic_delta']['state']);
        self::assertSame(['PROPOSAL', 'ELIGIBILITY', 'CONTROLLED_APPLY'], $result['governance']['lifecycle']);
        self::assertSame($canonicalId, $result['writes'][0]['canonical_readback']['canonical_id']);
    }

    public function test_stale_subject_revision_hard_blocks_required_semantic_delta(): void
    {
        $subject = UuidCodec::newV7();
        $proposalId = UuidCodec::newV7();
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn([
            'state' => 'approved', 'entity_type' => 'relation', 'operation' => 'relation_create', 'subject_id' => '1:573',
            'payload' => ['source_type' => 'wp_post', 'source_uuid' => '1:573', 'target_type' => 'variant', 'target_uuid' => $subject, 'target_revision' => 2, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION'],
            'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency', 'revision' => 1,
        ]);
        $governance->expects(self::once())->method('eligibility')->with($proposalId)->willReturn(['ready' => false, 'reasons' => ['TARGET_REVISION_CHANGED']]);
        $service = new GovernedCaptureContinuationService($governance, static fn (): array => throw new \LogicException('stale subject must not apply'), $this->policies(['relation']), static fn (): bool => true);

        $result = $service->execute('capture-stale-subject', 'resume-stale-subject', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA', 'source' => 'CAPTURE', 'semantic_delta' => ['status' => 'REQUIRED']],
            'subject_resolution' => ['primary' => ['id' => $subject, 'type' => 'variant', 'revision' => 3], 'resolved' => [['id' => $subject, 'type' => 'variant', 'revision' => 3]]],
        ], ['proposal_ids' => [$proposalId]]);

        self::assertSame('HARD_BLOCK', $result['requirements']['semantic_delta']['policy']);
        self::assertSame('BLOCKED', $result['requirements']['semantic_delta']['state']);
        self::assertSame('SYSTEM_BLOCKED', $result['status']);
        self::assertContains('TARGET_REVISION_CHANGED', $result['blockers']);
    }

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

    public function test_knowledge_delta_supports_exact_classification_subject_with_entity_scope(): void
    {
        $subject = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $subject, 'ingest', ['text' => 'Đồng hồ công cộng phục vụ nhiều người.'], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'continuation:classification-knowledge', entityType: 'knowledge');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static function (array $arguments) use ($subject): bool {
            return ($arguments['entity_type'] ?? '') === 'knowledge'
                && ($arguments['subject_id'] ?? '') === $subject
                && ($arguments['payload']['provenance']['metadata']['subject_type'] ?? '') === 'classification'
                && ($arguments['payload']['provenance']['metadata']['scope'] ?? '') === 'entity';
        }))->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposal->id)->willReturnOnConsecutiveCalls(
            ['state' => 'draft', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
            ['state' => 'submitted', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
        );
        $governance->expects(self::once())->method('submit')->with($proposal->id)->willReturn($proposal->transition(ProposalState::SUBMITTED));
        $service = new GovernedCaptureContinuationService($governance, static fn (): array => [], $this->policies(), static fn (): bool => true);

        $result = $service->execute('capture-classification', 'continuation:classification', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
            'subject_resolution' => ['resolved' => [['id' => $subject, 'type' => 'classification']]],
            'continuation_delta_text' => 'Đồng hồ công cộng phục vụ nhiều người.',
            'interpretation' => [],
            'observations' => [],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame($proposal->id, $result['writes'][0]['proposal_id']);
    }

    public function test_article_continuation_plans_one_governed_about_relation_for_exact_primary_subject(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $plans = (new \ReflectionMethod($service, 'plans'));
        $plans->setAccessible(true);
        $subject = UuidCodec::newV7();
        $planned = $plans->invoke($service, 'capture-article', 'continuation', [
            'article_id' => 485,
            'article_endpoint_key' => '1:485',
            'content_intent' => ['intent' => 'TEXT_ARTICLE'],
            'subject_resolution' => ['primary' => ['id' => $subject, 'type' => 'classification'], 'resolved' => [['id' => $subject, 'type' => 'classification']]],
        ], true);

        self::assertCount(1, $planned);
        self::assertSame('relation', $planned[0]['entity_type']);
        self::assertSame('relation_create', $planned[0]['operation']);
        self::assertSame('1:485', $planned[0]['payload']['source_uuid']);
        self::assertSame($subject, $planned[0]['payload']['target_uuid']);
        self::assertSame('about', $planned[0]['payload']['predicate']);
    }

    public function test_capture_provenance_packets_plan_source_and_resolved_evidence_without_reparsing_claim_text(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $plans = (new \ReflectionMethod($service, 'plans'));
        $plans->setAccessible(true);
        $claimId = UuidCodec::newV7();
        $sourceId = UuidCodec::newV7();
        $planned = $plans->invoke($service, 'capture-knowledge', 'continuation', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
            'provenance_packets' => [
                'sources' => [[
                    'stable_key' => 'nhk:source:public-clock:ahs-turret-group',
                    'title' => 'AHS Turret Clock Group',
                    'source_type' => 'website',
                    'locator' => 'https://www.ahsoc.org/groups/turret-clock-group/about-the-turret-clock-group/',
                    'metadata' => ['visibility' => 'PUBLIC'],
                ]],
                'evidence' => [[
                    'claim_id' => $claimId,
                    'source_id' => $sourceId,
                    'excerpt' => 'The source describes turret clocks and public timekeeping.',
                    'relation' => 'supports',
                    'locator' => 'https://www.ahsoc.org/groups/turret-clock-group/about-the-turret-clock-group/',
                    'metadata' => ['visibility' => 'PUBLIC'],
                ]],
            ],
            'subject_resolution' => ['resolved' => []],
        ], false);

        self::assertCount(2, $planned);
        self::assertSame(['source', 'evidence'], array_column($planned, 'entity_type'));
        self::assertSame('nhk:source:public-clock:ahs-turret-group', $planned[0]['payload']['stable_key']);
        self::assertSame($claimId, $planned[1]['payload']['claim_id']);
        self::assertSame($sourceId, $planned[1]['payload']['source_id']);
    }

    public function test_video_review_required_exposes_governance_and_canonical_identity_separately(): void
    {
        $videoId = UuidCodec::newV7();
        $proposalId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, $videoId, 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:pending:video', targetUuid: $videoId, entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposalId)->willReturnOnConsecutiveCalls(
            ['proposal_id' => $proposalId, 'state' => 'draft', 'entity_type' => 'video', 'target_uuid' => $videoId, 'payload' => ['canonical_id' => $videoId], 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
            ['proposal_id' => $proposalId, 'state' => 'submitted', 'entity_type' => 'video', 'target_uuid' => $videoId, 'payload' => ['canonical_id' => $videoId], 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
        );
        $governance->expects(self::once())->method('submit')->with($proposalId)->willReturn($proposal->transition(ProposalState::SUBMITTED));
        $governance->expects(self::never())->method('approve');
        $service = new GovernedCaptureContinuationService($governance, static fn (): array => throw new \LogicException('apply must not run before approval'), $this->policies(['video']), static fn (): bool => true);

        $result = $service->execute('capture-pending', 'capture-pending:video', [
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'payload' => ['canonical_id' => $videoId]]]],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame($proposalId, $result['writes'][0]['proposal_id']);
        self::assertSame('submitted', $result['writes'][0]['proposal_state']);
        self::assertSame($videoId, $result['writes'][0]['target_uuid']);
        self::assertNull($result['writes'][0]['canonical_id']);
        self::assertSame($proposalId, $result['proposal_id']);
        self::assertSame('submitted', $result['proposal_state']);
        self::assertSame($videoId, $result['target_uuid']);
        self::assertNull($result['canonical_id']);
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

    public function test_stale_relation_binding_reenters_generic_governed_reconciliation_boundary(): void
    {
        $proposalId = UuidCodec::newV7();
        $replacementId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, '1:485', 'relation_create', [
            'source_type' => 'wp_post', 'source_uuid' => '1:485',
            'target_type' => 'classification', 'target_uuid' => UuidCodec::newV7(),
            'predicate' => 'about', 'source_revision' => 1, 'target_revision' => 1,
        ], 'content', null, 'dependency', ProposalState::APPROVED, entityType: 'relation');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn([
            'state' => 'approved', 'entity_type' => 'relation', 'operation' => 'relation_create',
            'subject_id' => '1:485', 'payload' => $proposal->payload,
            'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency', 'revision' => 1,
        ]);
        $governance->expects(self::once())->method('eligibility')->with($proposalId)->willReturn([
            'ready' => false, 'reasons' => ['TARGET_REVISION_CHANGED'],
        ]);
        $reconciled = false;
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (): array => throw new \LogicException('stale relation must not apply'),
            $this->policies(['relation']),
            static fn (string $capability): bool => true,
            proposalReconciliation: static function (Proposal $stale, array $eligibility, array $control) use (&$reconciled, $proposalId, $replacementId): array {
                $reconciled = true;
                return ['proposal_id' => $replacementId, 'status' => 'APPLIED', 'replaced_proposal_id' => $proposalId, 'canonical_id' => 'edge-1', 'canonical_readback' => ['canonical_id' => 'edge-1', 'active' => true]];
            },
        );

        $result = $service->execute('capture-485', 'continuation', [], ['proposal_ids' => [$proposalId]]);

        self::assertTrue($reconciled);
        self::assertSame('APPLIED', $result['status']);
        self::assertSame($replacementId, $result['writes'][0]['proposal_id']);
    }

    public function test_stale_video_update_is_not_dispatched_to_relation_reconciliation(): void
    {
        $proposalId = UuidCodec::newV7();
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, $videoId, 'update', [
            'canonical_id' => $videoId,
            'metadata' => ['semantic_reconciliation_requested' => true],
        ], 'content', 1, 'dependency', ProposalState::APPROVED, entityType: 'video', targetUuid: $videoId);
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn([
            'state' => 'approved',
            'entity_type' => 'video',
            'operation' => 'update',
            'subject_id' => $videoId,
            'target_uuid' => $videoId,
            'payload' => $proposal->payload,
            'expected_revision' => 1,
            'content_fingerprint' => 'content',
            'dependency_fingerprint' => 'dependency',
            'revision' => 1,
        ]);
        $governance->expects(self::once())->method('eligibility')->with($proposalId)->willReturn([
            'ready' => false,
            'reasons' => ['TARGET_REVISION_CHANGED'],
        ]);

        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (): array => throw new \LogicException('stale video update must not apply'),
            $this->policies(['video'], ['video' => 'AUTO_PUBLISH']),
            static fn (): bool => true,
            proposalReconciliation: static fn (): array => [
                'status' => 'SYSTEM_BLOCKED',
                'blockers' => ['RELATION_RECONCILIATION_UNSUPPORTED'],
            ],
        );

        $result = $service->execute('capture-video', 'continuation', [], ['proposal_ids' => [$proposalId]]);

        self::assertSame('SYSTEM_BLOCKED', $result['status']);
        self::assertSame(['TARGET_REVISION_CHANGED'], $result['blockers']);
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
        $relationProposal = new Proposal(UuidCodec::newV7(), 'relation', 'relation_create', [], 'relation-content', null, 'relation-dependency', ProposalState::DRAFT, idempotencyKey: 'capture:auto:relation', entityType: 'relation');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::exactly(2))->method('createFromArguments')->willReturnOnConsecutiveCalls($proposal, $relationProposal);
        $governance->expects(self::exactly(2))->method('submit')->willReturnOnConsecutiveCalls($proposal, $relationProposal);
        $governance->expects(self::exactly(4))->method('review')->willReturnOnConsecutiveCalls(
            ['state' => 'draft', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
            ['state' => 'submitted', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
            ['state' => 'draft', 'entity_type' => 'relation', 'content_fingerprint' => 'relation-content', 'dependency_fingerprint' => 'relation-dependency'],
            ['state' => 'submitted', 'entity_type' => 'relation', 'content_fingerprint' => 'relation-content', 'dependency_fingerprint' => 'relation-dependency'],
        );
        $governance->expects(self::exactly(2))->method('approve')->willReturnOnConsecutiveCalls($proposal->transition(ProposalState::APPROVED, 'system'), $relationProposal->transition(ProposalState::APPROVED, 'system'));
        $governance->expects(self::exactly(2))->method('eligibility')->willReturn(['ready' => true]);
        $applied = [];
        $service = new GovernedCaptureContinuationService($governance, static function (string $id) use (&$applied): array {
            $applied[] = $id;
            $isRelation = count($applied) === 2;
            return ['canonical_id' => $isRelation ? 'edge-1' : 'claim-1', 'canonical_readback' => ['canonical_id' => $isRelation ? 'edge-1' : 'claim-1', 'entity_type' => $isRelation ? 'relation' : 'knowledge', 'active' => true, 'revision' => 1]];
        }, $this->policies(['knowledge', 'relation'], ['knowledge' => 'AUTO_PUBLISH', 'relation' => 'AUTO_PUBLISH']), static fn (string $capability): bool => true);

        $result = $service->execute('capture-1', 'capture-1:semantic', [
            'subject_resolution' => ['resolved' => [['id' => $variant, 'type' => 'variant']]],
            'interpretation' => ['user_claim_candidates' => [['text' => 'Cấu hình 10 côn.', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]],
            'observations' => [],
        ]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame([$proposal->id, $relationProposal->id], $applied);
        self::assertSame(['canonical_id' => 'claim-1', 'entity_type' => 'knowledge', 'active' => true, 'revision' => 1], $result['writes'][0]['canonical_readback']);
    }

    public function test_auto_publish_submits_video_proposal_from_capture_asset_without_duplicate_writer(): void
    {
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:auto:video', entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static fn (array $args): bool => ($args['entity_type'] ?? '') === 'video' && ($args['operation'] ?? '') === 'ingest'))->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposal->id)->willReturnOnConsecutiveCalls(['state' => 'draft', 'entity_type' => 'video', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'], ['state' => 'submitted', 'entity_type' => 'video', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
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

    public function test_explicit_video_resume_reenters_original_child_without_new_video_payload(): void
    {
        $governance = $this->createMock(GovernedLifecycle::class);
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:resume:video', entityType: 'video');
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static fn (array $args): bool => ($args['entity_type'] ?? '') === 'video' && ($args['payload']['canonical_id'] ?? '') === $videoId))->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposal->id)->willReturnOnConsecutiveCalls(['state' => 'draft', 'entity_type' => 'video', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'], ['state' => 'submitted', 'entity_type' => 'video', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('approve')->willReturn($proposal->transition(ProposalState::APPROVED, 'system'));
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $service = new GovernedCaptureContinuationService($governance, static fn (string $id): array => ['canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId]], $this->policies(['video'], ['video' => 'AUTO_PUBLISH']), static fn (string $capability): bool => true);

        $result = $service->execute('capture-resume', 'resume-video', [
            'existing_capture_continuation' => true,
            'continuation_delta_text' => 'Bổ sung lý do cần đọc lại Video.',
            'subject_resolution' => ['resolved' => []], 'interpretation' => [], 'observations' => [],
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]],
        ], ['resume_children' => ['video']]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame($videoId, $result['writes'][0]['canonical_id']);
    }

    public function test_explicit_video_resume_creates_same_video_editorial_update_from_current_delta(): void
    {
        $videoId = UuidCodec::newV7();
        $videos = new class($videoId) implements VideoRepository {
            public Video $video;
            public function __construct(string $id) { $this->video = new Video($id, 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Nguồn video', ['source' => ['source_title' => 'Nguồn video', 'external_video_id' => 'dQw4w9WgXcQ'], 'editorial' => ['title' => 'OLD', 'summary' => 'OLD SUMMARY', 'body' => 'OLD BODY', 'why_this_matters' => 'OLD WHY']]); }
            public function findByCanonicalId(string $id): ?Video { return $id === $this->video->canonicalId ? $this->video : null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return $platform === $this->video->platform && $externalId === $this->video->externalVideoId ? $this->video : null; }
            public function create(Video $video): Video { return $this->video = $video; }
            public function update(Video $video, int $expectedRevision): Video { return $this->video = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $video->metadata, $video->thumbnailMediaId, $video->active, $expectedRevision + 1); }
            public function list(bool $includeRetired = false): array { return [$this->video]; }
        };
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'update', [], 'content', 1, 'dependency', ProposalState::DRAFT, idempotencyKey: 'resume-editorial', targetUuid: $videoId, entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static function (array $args) use ($videoId): bool {
            return ($args['operation'] ?? '') === 'update'
                && ($args['entity_type'] ?? '') === 'video'
                && ($args['subject_id'] ?? '') === $videoId
                && ($args['target_uuid'] ?? '') === $videoId
                && ($args['payload']['metadata']['editorial']['summary'] ?? '') !== 'OLD SUMMARY';
        }))->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal->transition(ProposalState::SUBMITTED));
        $governance->method('review')->willReturn(['state' => 'approved', 'entity_type' => 'video', 'operation' => 'update', 'subject_id' => $videoId, 'target_uuid' => $videoId, 'payload' => $proposal->payload, 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $planner = new VideoEditorialResumePlanner($videos, new VideoEditorialGenerator(), new VideoSeoProjection());
        $service = new GovernedCaptureContinuationService($governance, static fn (): array => ['canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId, 'active' => true, 'revision' => 2]], $this->policies(['video'], ['video' => 'AUTO_PUBLISH']), static fn (): bool => true, null, null, null, null, null, null, null, null, $planner);

        $result = $service->execute('capture-resume', 'resume-editorial', [
            'capture_id' => 'capture-resume',
            'existing_capture_continuation' => true,
            'continuation_delta_text' => 'Giải thích giá trị sưu tầm của video này.',
            'subject_resolution' => ['primary' => ['id' => '22222222-2222-4222-8222-222222222222', 'type' => 'variant', 'name' => 'Odo 36/8']],
            'retrieval' => ['selected_claims' => [['id' => 'claim-1', 'revision' => 4]]],
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]],
        ], ['resume_children' => ['video']]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame($videoId, $result['writes'][0]['canonical_id']);
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
        self::assertSame('changed diagnostic wording', $result['error']);
    }

    public function test_brand_knowledge_scope_is_derived_from_locked_subject_not_interpreter_default(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $method = new \ReflectionMethod($service, 'plans');
        $method->setAccessible(true);
        $brand = UuidCodec::newV7();
        $plans = $method->invoke($service, 'capture-brand', 'continuation', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
            'subject_resolution' => ['resolved' => [['id' => $brand, 'type' => 'brand']]],
            'interpretation' => ['user_claim_candidates' => [['text' => 'Hermle được thành lập năm 1922.', 'scope' => 'variant', 'facet' => 'identity']]],
        ]);

        self::assertCount(2, $plans);
        self::assertSame('brand', $plans[0]['payload']['provenance']['metadata']['scope']);
    }

    public function test_knowledge_plan_emits_governed_about_relation_to_locked_subject(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $method = new \ReflectionMethod($service, 'plans');
        $method->setAccessible(true);
        $brand = UuidCodec::newV7();
        $plans = $method->invoke($service, 'capture-brand', 'continuation', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
            'subject_resolution' => ['resolved' => [['id' => $brand, 'type' => 'brand']]],
            'interpretation' => ['user_claim_candidates' => [['text' => 'Hermle được thành lập năm 1922.', 'facet' => 'identity']]],
        ]);

        self::assertSame(['knowledge', 'relation'], array_column($plans, 'entity_type'));
        self::assertSame('about', $plans[1]['payload']['predicate']);
        self::assertSame($brand, $plans[1]['payload']['target_uuid']);
        self::assertSame('knowledge', $plans[1]['payload']['source_type']);
    }

    public function test_active_knowledge_about_edge_is_reused_without_duplicate_governance_proposal(): void
    {
        $variant = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $variant, 'ingest', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:knowledge', entityType: 'knowledge');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static fn (array $plan): bool => ($plan['entity_type'] ?? '') === 'knowledge'))->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal);
        $governance->method('review')->willReturnOnConsecutiveCalls(['state' => 'draft', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'], ['state' => 'submitted', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('approve')->willReturn($proposal->transition(ProposalState::APPROVED, 'system'));
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (): array => ['canonical_id' => 'claim-1', 'canonical_readback' => ['canonical_id' => 'claim-1', 'active' => true, 'revision' => 1]],
            $this->policies(['knowledge', 'relation'], ['knowledge' => 'AUTO_PUBLISH', 'relation' => 'AUTO_PUBLISH']),
            static fn (): bool => true,
            relationState: static fn (): array => ['status' => 'ACTIVE', 'canonical_id' => 'edge-1', 'revision' => 2, 'active' => true],
        );

        $result = $service->execute('capture-1', 'capture:knowledge', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
            'subject_resolution' => ['resolved' => [['id' => $variant, 'type' => 'variant']]],
            'interpretation' => ['user_claim_candidates' => [['text' => 'Cấu hình 10 côn.', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]],
        ]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame('REUSED_VERIFIED', $result['writes'][1]['status']);
        self::assertSame('edge-1', $result['writes'][1]['canonical_id']);
    }

    public function test_rate_limit_is_retryable_and_has_stable_external_failure_code(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $method = new \ReflectionMethod($service, 'classifiedFailure');
        $method->setAccessible(true);
        $result = $method->invoke($service, ['entity_type' => 'knowledge'], new \RuntimeException('HTTP 429 Too Many Requests; Retry-After: 10'));

        self::assertSame('FAILED_RETRYABLE', $result['status']);
        self::assertSame(['EXTERNAL_RATE_LIMIT'], $result['blockers']);
    }

    public function test_applied_proposal_replay_uses_persisted_readback_without_reapplying(): void
    {
        $proposalId = UuidCodec::newV7();
        $claimId = UuidCodec::newV7();
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn([
            'state' => 'applied', 'entity_type' => 'knowledge', 'operation' => 'ingest', 'subject_id' => $claimId, 'target_uuid' => $claimId, 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency',
            'canonical_readback' => ['canonical_id' => $claimId, 'entity_type' => 'knowledge', 'active' => true, 'revision' => 2],
        ]);
        $service = new GovernedCaptureContinuationService($governance, static fn (): array => throw new \LogicException('APPLIED proposal must not be applied again'), $this->policies(), static fn (): bool => true);

        $result = $service->execute('capture-1', 'replay', [], ['proposal_ids' => [$proposalId]]);

        self::assertSame('APPLIED', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertTrue($result['writes'][0]['idempotent']);
    }

    private function policies(array $types = ['knowledge'], array $stored = []): GovernanceAutomationPolicyResolver
    {
        return new GovernanceAutomationPolicyResolver($types, new class($stored) implements AutomationPolicyStorage {
            public function __construct(private array $stored) {}
            public function read(): array { return $this->stored; }
            public function write(array $policies): void {}
        });
    }

    /** @return list<string> */
    private function relationTypes(array $result): array
    {
        return array_values(array_map(static function (array $plan): string {
            $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
            if (($plan['entity_type'] ?? '') !== 'relation' || ($payload['source_type'] ?? '') !== 'wp_post' || ($payload['predicate'] ?? '') !== 'about') return '';
            return 'wp_post --about--> subject';
        }, array_values(array_filter((array) ($result['plans'] ?? []), 'is_array'))));
    }
}
