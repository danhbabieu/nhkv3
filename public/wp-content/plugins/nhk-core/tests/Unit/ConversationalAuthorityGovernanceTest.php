<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\ConversationalAuthorityPolicyResolver;
use NHK\Core\Application\Governance\GovernedAuthorityPlanExecutor;
use NHK\Core\Application\Mcp\McpGovernanceHandler;
use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Domain\Governance\{AutomationMode, ConversationalAuthorityPolicy, ProposalState};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Governance\ControlledApplyService;
use NHK\Core\Contracts\Governance\ApplyAttemptRepository;
use NHK\Core\Contracts\Shared\TransactionManager;
use NHK\Core\Domain\Governance\ApplyAttempt;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class ConversationalAuthorityGovernanceTest extends TestCase
{
    public function test_effective_policy_is_the_strictest_of_global_and_authority_settings(): void
    {
        self::assertSame(ConversationalAuthorityPolicy::REVIEW_REQUIRED, ConversationalAuthorityPolicyResolver::effective(AutomationMode::REVIEW_REQUIRED, ConversationalAuthorityPolicy::AUTO_APPROVE_AFTER_OWNER_CONFIRMATION));
        self::assertSame(ConversationalAuthorityPolicy::REVIEW_REQUIRED, ConversationalAuthorityPolicyResolver::effective(AutomationMode::AUTO_APPROVE, ConversationalAuthorityPolicy::REVIEW_REQUIRED));
        self::assertSame(ConversationalAuthorityPolicy::OFF, ConversationalAuthorityPolicyResolver::effective(AutomationMode::AUTO_APPROVE, ConversationalAuthorityPolicy::OFF));
        self::assertSame(ConversationalAuthorityPolicy::AUTO_APPROVE_AFTER_OWNER_CONFIRMATION, ConversationalAuthorityPolicyResolver::effective(AutomationMode::AUTO_APPROVE, ConversationalAuthorityPolicy::AUTO_APPROVE_AFTER_OWNER_CONFIRMATION));
    }

    public function test_exact_candidate_approval_creates_only_the_selected_proposal(): void
    {
        $repository = new InMemoryProposalRepository();
        $handler = new McpGovernanceHandler(new GovernanceService($repository));
        $plan = ['reuse' => [], 'create_candidates' => [
            ['candidate_id' => 'candidate-1', 'action' => 'CREATE', 'entity_type' => 'brand', 'proposed_canonical_name' => 'Hermle', 'stable_key_preview' => 'nhk:brand:hermle', 'dependencies' => []],
            ['candidate_id' => 'candidate-2', 'action' => 'CREATE', 'entity_type' => 'classification', 'family' => 'clock-type', 'proposed_canonical_name' => 'Đồng hồ công cộng', 'stable_key_preview' => 'nhk:classification:clock-type.dong-ho-cong-cong', 'dependencies' => []],
        ], 'update_candidates' => [], 'relation_candidates' => []];

        $result = (new GovernedAuthorityPlanExecutor($handler))->execute($plan, str_repeat('a', 64), str_repeat('a', 64), ['candidate-1'], ConversationalAuthorityPolicy::REVIEW_REQUIRED);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame(['candidate-1'], $result['approved_candidate_ids']);
        self::assertCount(1, $result['proposal_ids']);
        self::assertSame('brand', $repository->find($result['proposal_ids'][0])?->entityType);
    }

    public function test_changed_plan_fingerprint_blocks_before_proposal_creation(): void
    {
        $repository = new InMemoryProposalRepository();
        $handler = new McpGovernanceHandler(new GovernanceService($repository));
        $plan = ['reuse' => [], 'create_candidates' => [['candidate_id' => 'candidate-1', 'action' => 'CREATE', 'entity_type' => 'brand', 'proposed_canonical_name' => 'Hermle', 'stable_key_preview' => 'nhk:brand:hermle', 'dependencies' => []]], 'update_candidates' => [], 'relation_candidates' => []];

        $result = (new GovernedAuthorityPlanExecutor($handler))->execute($plan, str_repeat('a', 64), str_repeat('b', 64), ['candidate-1'], ConversationalAuthorityPolicy::REVIEW_REQUIRED);

        self::assertSame('PLAN_REAPPROVAL_REQUIRED', $result['status']);
        self::assertSame([], $result['proposal_ids']);
    }

    public function test_multi_candidate_apply_rolls_back_canonical_partial_state_and_keeps_failure_diagnostic(): void
    {
        $repository = new InMemoryProposalRepository();
        $first = \NHK\Core\Shared\Uuid\UuidCodec::newV7(); $second = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        foreach ([$first, $second] as $id) $repository->create(new \NHK\Core\Domain\Governance\Proposal($id, 'brand', 'create', ['stable_key' => 'nhk:brand:' . $id, 'name' => 'Test'], 'content', null, 'deps', ProposalState::APPROVED, actor: '1', idempotencyKey: 'batch-' . $id, entityType: 'brand'));
        $attempts = new class implements ApplyAttemptRepository {
            public array $items = [];
            public function nextAttemptNumberLocked(string $proposalId): int { return count(array_filter($this->items, static fn (ApplyAttempt $item): bool => $item->proposalId === $proposalId)) + 1; }
            public function createRunning(ApplyAttempt $attempt): ApplyAttempt { return $this->items[$attempt->id] = $attempt; }
            public function markSucceeded(string $attemptId, ?string $resultEntityUuid): ApplyAttempt { $old = $this->items[$attemptId]; return $this->items[$attemptId] = new ApplyAttempt($old->id, $old->proposalId, $old->number, 'succeeded', $resultEntityUuid, finishedAt: 'now'); }
            public function persistFailed(ApplyAttempt $attempt): ApplyAttempt { return $this->items[$attempt->id] = $attempt; }
            public function findByProposal(string $proposalId): array { return array_values(array_filter($this->items, static fn (ApplyAttempt $item): bool => $item->proposalId === $proposalId)); }
            public function findSuccessful(string $proposalId): ?ApplyAttempt { foreach ($this->findByProposal($proposalId) as $item) if ($item->state === 'succeeded') return $item; return null; }
        };
        $canonical = []; $firstTransaction = true;
        $transactions = new class($canonical, $firstTransaction) implements TransactionManager {
            public function __construct(private array &$canonical, private bool &$firstTransaction) {}
            public function begin(): void {} public function commit(): void {} public function rollback(): void {}
            public function transactional(callable $callback): mixed { try { return $callback(); } catch (\Throwable $error) { if ($this->firstTransaction) { $this->canonical = []; $this->firstTransaction = false; } throw $error; } }
            public function run(callable $callback): mixed { return $this->transactional($callback); }
        };
        $apply = new ControlledApplyService($repository, $attempts, $transactions, static function (\NHK\Core\Domain\Governance\Proposal $proposal) use (&$canonical, $second): string { $canonical[] = $proposal->id; if ($proposal->id === $second) throw new \RuntimeException('forced-batch-failure'); return \NHK\Core\Shared\Uuid\UuidCodec::newV7(); });

        try { $apply->applyMany([$first, $second]); self::fail('Expected batch failure.'); } catch (\RuntimeException $error) { self::assertSame('forced-batch-failure', $error->getMessage()); }
        self::assertSame([], $canonical);
        self::assertNotEmpty(array_filter($attempts->findByProposal($first), static fn (ApplyAttempt $attempt): bool => $attempt->state === 'failed'));
    }
}
