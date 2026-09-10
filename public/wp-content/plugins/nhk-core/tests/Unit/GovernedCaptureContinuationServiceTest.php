<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\GovernedCaptureContinuationService;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
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

    private function policies(array $types = ['knowledge']): GovernanceAutomationPolicyResolver
    {
        return new GovernanceAutomationPolicyResolver($types, new class implements AutomationPolicyStorage {
            public function read(): array { return []; }
            public function write(array $policies): void {}
        });
    }
}
