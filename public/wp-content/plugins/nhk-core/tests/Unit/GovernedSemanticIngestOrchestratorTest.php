<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernedSemanticIngestOrchestrator;
use NHK\Core\Contracts\Governance\GovernedLifecycle;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class GovernedSemanticIngestOrchestratorTest extends TestCase
{
    public function test_orchestration_uses_governance_order_and_requires_readback(): void
    {
        $proposal = new Proposal(UuidCodec::newV7(), 'source', 'ingest', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'source');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::once())->method('submit')->with($proposal->id)->willReturn($proposal);
        $governance->expects(self::once())->method('review')->with($proposal->id)->willReturn(['state' => 'submitted']);
        $governance->expects(self::once())->method('approve')->with($proposal->id, 'content', 'dependency', 'orchestrator')->willReturn($proposal);
        $governance->expects(self::once())->method('eligibility')->with($proposal->id)->willReturn(['ready' => true]);
        $runner = new GovernedSemanticIngestOrchestrator($governance, static fn (array $review): bool => true, static fn (string $id): array => ['canonical_readback' => ['entity_type' => 'source', 'canonical_id' => UuidCodec::newV7(), 'active' => true, 'revision' => 1, 'snapshot' => ['id' => $id]]]);
        self::assertSame('applied', $runner->run([['operation' => 'ingest', 'entity_type' => 'source', 'payload' => []]])[0]['proposal_state']);
    }

    public function test_orchestration_does_not_auto_approve_when_policy_requires_manual_approval(): void
    {
        $proposal = new Proposal(UuidCodec::newV7(), 'source', 'ingest', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'manual');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::once())->method('submit')->willReturn($proposal);
        $governance->expects(self::once())->method('review')->willReturn(['state' => 'submitted']);
        $governance->expects(self::never())->method('approve');
        $runner = new GovernedSemanticIngestOrchestrator($governance, static fn (array $review): bool => false, static fn (string $id): array => []);
        $this->expectExceptionMessage('MANUAL_APPROVAL_REQUIRED');
        $runner->run([['operation' => 'ingest', 'entity_type' => 'source', 'payload' => []]]);
    }

    public function test_orchestration_blocks_node_with_unverified_dependency(): void
    {
        $proposal = new Proposal(UuidCodec::newV7(), 'evidence', 'ingest', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'evidence');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::never())->method('createFromArguments');
        $runner = new GovernedSemanticIngestOrchestrator($governance, static fn (array $review): bool => true, static fn (string $id): array => []);
        $this->expectExceptionMessage('DEPENDENCY_NOT_VERIFIED');
        $runner->run([['operation' => 'ingest', 'entity_type' => 'evidence', 'dependency_ids' => [$proposal->id], 'payload' => []]]);
    }
}
