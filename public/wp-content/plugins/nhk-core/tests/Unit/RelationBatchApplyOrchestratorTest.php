<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\RelationBatchApplyOrchestrator;
use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpToolCatalog};
use NHK\Core\Contracts\Governance\GovernedLifecycle;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class RelationBatchApplyOrchestratorTest extends TestCase
{
    public function test_governed_relation_batch_tool_is_exposed(): void
    {
        self::assertTrue(McpToolCatalog::isGoverned('nhk.relation.backfill.apply'));
        self::assertSame('nhk-v3/relation-backfill-apply', McpAbilityRegistration::abilityNameForTool('nhk.relation.backfill.apply'));
    }

    public function test_applies_exact_candidates_through_governance_and_readback(): void
    {
        $proposal = $this->proposal('source-1');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::once())->method('submit')->with($proposal->id)->willReturn($proposal);
        $governance->expects(self::once())->method('review')->with($proposal->id)->willReturn(['state' => 'submitted', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->expects(self::once())->method('approve')->with($proposal->id, 'content', 'dependency', 'authenticated-admin')->willReturn($proposal);
        $governance->expects(self::once())->method('eligibility')->with($proposal->id)->willReturn(['ready' => true]);
        $apply = static fn (string $id): array => ['proposal_id' => $id, 'idempotent' => false, 'canonical_readback' => ['canonical_id' => 'edge-1']];

        $result = (new RelationBatchApplyOrchestrator($governance, $apply, static fn (array $review): bool => true, 'authenticated-admin'))->run([$this->candidate('source-1')]);

        self::assertSame(['created' => 1, 'idempotent' => 0, 'failed' => 0, 'denied' => 0], $result['counters']);
        self::assertSame('edge-1', $result['items'][0]['readback']['canonical_id']);
    }

    public function test_deduplicates_same_candidate_by_idempotency_key(): void
    {
        $proposal = $this->proposal('source-1');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal);
        $governance->method('review')->willReturn(['state' => 'submitted', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('approve')->willReturn($proposal);
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $apply = static fn (string $id): array => ['proposal_id' => $id, 'idempotent' => true, 'canonical_readback' => ['canonical_id' => 'edge-1']];

        $result = (new RelationBatchApplyOrchestrator($governance, $apply, static fn (array $review): bool => true, 'authenticated-admin'))->run([$this->candidate('source-1'), $this->candidate('source-1')]);

        self::assertSame(['created' => 0, 'idempotent' => 1, 'failed' => 0, 'denied' => 0], $result['counters']);
    }

    public function test_continues_after_partial_failure_and_records_failure(): void
    {
        $first = $this->proposal('source-1');
        $second = $this->proposal('source-2');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->method('createFromArguments')->willReturnOnConsecutiveCalls($first, $second);
        $governance->method('submit')->willReturnOnConsecutiveCalls($first, $second);
        $governance->method('review')->willReturnOnConsecutiveCalls(['state' => 'submitted', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'], ['state' => 'submitted', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('approve')->willReturnOnConsecutiveCalls($first, $second);
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $apply = static function (string $id): array { if ($id === 'proposal-source-1') throw new \RuntimeException('APPLY_FAILED'); return ['proposal_id' => $id, 'idempotent' => false, 'canonical_readback' => ['canonical_id' => 'edge-2']]; };

        $result = (new RelationBatchApplyOrchestrator($governance, $apply, static fn (array $review): bool => true, 'authenticated-admin'))->run([$this->candidate('source-1'), $this->candidate('source-2')]);

        self::assertSame(['created' => 1, 'idempotent' => 0, 'failed' => 1, 'denied' => 0], $result['counters']);
        self::assertSame('APPLY_FAILED', $result['items'][0]['error']);
    }

    public function test_denied_approval_does_not_apply(): void
    {
        $proposal = $this->proposal('source-1');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::once())->method('submit')->willReturn($proposal);
        $governance->expects(self::once())->method('review')->willReturn(['state' => 'submitted', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->expects(self::never())->method('approve');
        $apply = $this->createMock(\stdClass::class);

        $result = (new RelationBatchApplyOrchestrator($governance, static fn (): array => throw new \LogicException('must not apply'), static fn (array $review): bool => false, 'authenticated-admin'))->run([$this->candidate('source-1')]);

        self::assertSame(['created' => 0, 'idempotent' => 0, 'failed' => 0, 'denied' => 1], $result['counters']);
        self::assertSame('MANUAL_APPROVAL_REQUIRED', $result['items'][0]['error']);
    }

    public function test_rejects_non_exact_candidate_before_proposal_creation(): void
    {
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::never())->method('createFromArguments');

        $result = (new RelationBatchApplyOrchestrator($governance, static fn (): array => [], static fn (array $review): bool => true, 'authenticated-admin'))->run([[
            'sourceUuid' => 'not-a-uuid', 'sourceType' => 'knowledge', 'targetUuid' => 'not-a-uuid', 'targetType' => 'brand', 'predicate' => 'about', 'reason' => 'KEYWORD_GUESS', 'confidence' => 0,
        ]]);

        self::assertSame(1, $result['counters']['failed']);
        self::assertSame('NON_DETERMINISTIC_CANDIDATE', $result['items'][0]['error']);
    }

    private function candidate(string $source): array
    {
        return ['sourceUuid' => $source, 'sourceType' => 'knowledge', 'targetUuid' => 'target-1', 'targetType' => 'brand', 'predicate' => 'about', 'reason' => 'STABLE_KEY_HIERARCHY'];
    }

    private function proposal(string $source): Proposal
    {
        return new Proposal('proposal-' . $source, 'relation', 'relation_create', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'legacy:' . $source, entityType: 'relation');
    }
}
