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

    public function test_entity_reuse_only_is_a_verified_idempotent_noop_without_a_proposal(): void
    {
        $repository = new InMemoryProposalRepository();
        $handler = new McpGovernanceHandler(new GovernanceService($repository));
        $canonicalId = '143ee093-5bc0-409f-a4ce-af559d18f16f';
        $result = (new GovernedAuthorityPlanExecutor($handler))->execute([
            'reuse' => [[
                'candidate_id' => 'candidate-reuse', 'action' => 'REUSE', 'entity_type' => 'music',
                'canonical_uuid' => $canonicalId, 'canonical_revision' => 3,
            ]],
            'create_candidates' => [], 'update_candidates' => [], 'relation_candidates' => [], 'relation_reuse' => [],
        ], str_repeat('a', 64), str_repeat('a', 64), ['candidate-reuse'], ConversationalAuthorityPolicy::AUTO_APPROVE_AFTER_OWNER_CONFIRMATION);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame([], $result['proposal_ids']);
        self::assertTrue($result['idempotent']);
        self::assertSame($canonicalId, $result['reused_candidates'][0]['canonical_id']);
        self::assertSame($canonicalId, $result['reused_candidates'][0]['canonical_readback']['canonical_id']);
        self::assertSame([], $result['apply_results']);
    }

    public function test_relation_reuse_keeps_legacy_reused_relations_and_adds_generic_readback(): void
    {
        $repository = new InMemoryProposalRepository();
        $handler = new McpGovernanceHandler(new GovernanceService($repository));
        $edgeId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $result = (new GovernedAuthorityPlanExecutor($handler))->execute([
            'reuse' => [], 'create_candidates' => [], 'update_candidates' => [], 'relation_candidates' => [],
            'relation_reuse' => [[
                'candidate_id' => 'relation-reuse', 'action' => 'REUSE', 'entity_type' => 'relation',
                'canonical_id' => $edgeId, 'revision' => 4, 'source_type' => 'music', 'target_type' => 'brand', 'predicate' => 'about',
            ]],
        ], str_repeat('a', 64), str_repeat('a', 64), ['relation-reuse'], ConversationalAuthorityPolicy::REVIEW_REQUIRED);

        self::assertSame('APPLIED', $result['status']);
        self::assertTrue($result['idempotent']);
        self::assertSame('relation-reuse', $result['reused_relations'][0]['candidate_id']);
        self::assertSame($edgeId, $result['reused_candidates'][0]['canonical_readback']['canonical_id']);
    }

    public function test_reuse_and_update_creates_only_the_update_proposal(): void
    {
        $repository = new InMemoryProposalRepository();
        $handler = new McpGovernanceHandler(new GovernanceService($repository));
        $canonicalId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $result = (new GovernedAuthorityPlanExecutor($handler))->execute([
            'reuse' => [['candidate_id' => 'candidate-reuse', 'action' => 'REUSE', 'entity_type' => 'music', 'canonical_uuid' => $canonicalId, 'canonical_revision' => 2]],
            'create_candidates' => [],
            'update_candidates' => [['candidate_id' => 'candidate-update', 'action' => 'UPDATE', 'entity_type' => 'music', 'canonical_uuid' => $canonicalId, 'canonical_revision' => 2, 'expected_revision' => 2, 'stable_key' => 'nhk:music:sonodo', 'entity_payload' => ['description' => 'updated'], 'dependencies' => []]],
            'relation_candidates' => [], 'relation_reuse' => [],
        ], str_repeat('b', 64), str_repeat('b', 64), ['candidate-reuse', 'candidate-update'], ConversationalAuthorityPolicy::REVIEW_REQUIRED);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertCount(1, $result['proposal_ids']);
        self::assertSame('candidate-reuse', $result['reused_candidates'][0]['candidate_id']);
        self::assertFalse($result['idempotent']);
        self::assertSame('update', $repository->find($result['proposal_ids'][0])?->operation);
    }

    public function test_reuse_and_create_creates_only_the_create_proposal(): void
    {
        $repository = new InMemoryProposalRepository();
        $handler = new McpGovernanceHandler(new GovernanceService($repository));
        $canonicalId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $result = (new GovernedAuthorityPlanExecutor($handler))->execute([
            'reuse' => [['candidate_id' => 'candidate-reuse', 'action' => 'REUSE', 'entity_type' => 'music', 'canonical_uuid' => $canonicalId, 'canonical_revision' => 2]],
            'create_candidates' => [['candidate_id' => 'candidate-create', 'action' => 'CREATE', 'entity_type' => 'brand', 'proposed_canonical_name' => 'New Brand', 'stable_key_preview' => 'nhk:brand:new-brand', 'dependencies' => []]],
            'update_candidates' => [], 'relation_candidates' => [], 'relation_reuse' => [],
        ], str_repeat('c', 64), str_repeat('c', 64), ['candidate-reuse', 'candidate-create'], ConversationalAuthorityPolicy::REVIEW_REQUIRED);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertCount(1, $result['proposal_ids']);
        self::assertSame('candidate-reuse', $result['reused_candidates'][0]['candidate_id']);
        self::assertSame('brand', $repository->find($result['proposal_ids'][0])?->entityType);
    }

    public function test_approved_authority_update_preserves_full_registry_payload_and_revision_binding(): void
    {
        $repository = new InMemoryProposalRepository();
        $handler = new McpGovernanceHandler(new GovernanceService($repository));
        $target = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $plan = [
            'reuse' => [],
            'create_candidates' => [],
            'update_candidates' => [[
                'candidate_id' => 'candidate-update',
                'action' => 'UPDATE',
                'entity_type' => 'brand',
                'canonical_uuid' => $target,
                'canonical_revision' => 3,
                'expected_revision' => 3,
                'canonical_name' => 'Hermle',
                'stable_key' => 'nhk:brand:hermle',
                'entity_payload' => ['description' => 'Mô tả.', 'country' => 'Đức'],
                'dependencies' => [],
            ]],
            'relation_candidates' => [],
        ];

        $result = (new GovernedAuthorityPlanExecutor($handler))->execute($plan, str_repeat('a', 64), str_repeat('a', 64), ['candidate-update'], ConversationalAuthorityPolicy::REVIEW_REQUIRED);
        $proposal = $repository->find($result['proposal_ids'][0]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame('update', $proposal?->operation);
        self::assertSame($target, $proposal?->targetUuid);
        self::assertSame(3, $proposal?->expectedRevision);
        self::assertSame(['description' => 'Mô tả.', 'country' => 'Đức'], $proposal?->payload['entity_payload']);
        self::assertSame('nhk:brand:hermle', $proposal?->payload['stable_key']);
    }

    public function test_approved_authority_rename_binds_requested_name_to_one_submitted_proposal(): void
    {
        $repository = new InMemoryProposalRepository();
        $handler = new McpGovernanceHandler(new GovernanceService($repository));
        $target = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $plan = [
            'reuse' => [], 'create_candidates' => [], 'relation_candidates' => [],
            'update_candidates' => [[
                'candidate_id' => 'candidate-rename',
                'action' => 'RENAME',
                'operation' => 'rename',
                'entity_type' => 'classification',
                'canonical_uuid' => $target,
                'canonical_revision' => 4,
                'expected_revision' => 4,
                'canonical_name' => 'Mặt Braz nằm',
                'requested_name' => 'Mặt bát giác nằm',
                'requested_delta' => ['name' => 'Mặt bát giác nằm'],
                'dependencies' => [],
            ]],
        ];

        $result = (new GovernedAuthorityPlanExecutor($handler))->execute($plan, str_repeat('a', 64), str_repeat('a', 64), ['candidate-rename'], ConversationalAuthorityPolicy::REVIEW_REQUIRED);
        $proposal = $repository->find($result['proposal_ids'][0]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame('rename', $proposal?->operation);
        self::assertSame($target, $proposal?->subjectId);
        self::assertSame(4, $proposal?->expectedRevision);
        self::assertSame('Mặt bát giác nằm', $proposal?->payload['name']);
        self::assertSame('candidate-rename', $proposal?->payload['candidate_id']);
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
