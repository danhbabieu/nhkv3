<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\GovernedCaptureContinuationService;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Semantic\ClaimReusePolicy;
use NHK\Core\Contracts\Governance\{AutomationPolicyStorage, GovernedLifecycle};
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
        $proposal = new Proposal(UuidCodec::newV7(), 'video-1', 'ingest', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:auto:video', entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static fn (array $args): bool => ($args['entity_type'] ?? '') === 'video' && ($args['operation'] ?? '') === 'ingest'))->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal);
        $governance->method('review')->willReturn(['state' => 'draft', 'entity_type' => 'video', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('approve')->willReturn($proposal->transition(ProposalState::APPROVED, 'system'));
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $service = new GovernedCaptureContinuationService($governance, static fn (string $id): array => ['canonical_id' => 'video-1', 'canonical_readback' => ['canonical_id' => 'video-1', 'entity_type' => 'video', 'active' => true, 'revision' => 1]], $this->policies(['video'], ['video' => 'AUTO_PUBLISH']), static fn (string $capability): bool => true);

        $result = $service->execute('capture-1', 'capture-1:semantic', ['subject_resolution' => ['resolved' => []], 'interpretation' => [], 'observations' => [], 'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['video_id' => 'video-1']]]]]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame('video-1', $result['writes'][0]['canonical_readback']['canonical_id']);
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
