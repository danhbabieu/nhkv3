<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernedAuthorityPlanExecutor;
use NHK\Core\Application\Governance\ControlledApplyService;
use NHK\Core\Application\Mcp\McpGovernanceHandler;
use NHK\Core\Contracts\Governance\{ApplyAttemptRepository, GovernanceAuditSink};
use NHK\Core\Contracts\Shared\TransactionManager;
use NHK\Core\Domain\Governance\{ApplyAttempt, GovernanceService, Proposal, ProposalState};
use NHK\Core\Domain\Governance\ConversationalAuthorityPolicy;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class RuntimeIdentityAndProjectBuildAuditTest extends TestCase
{
    public function test_authority_executor_binds_project_build_context_to_governance_proposal(): void
    {
        $repository = new InMemoryProposalRepository();
        $audit = new class implements GovernanceAuditSink {
            public array $events = [];
            public function record(string $event, Proposal $proposal): void { $this->events[] = [$event, $proposal]; }
            public function recordEvent(string $eventType, string $objectType, string $objectKey, ?int $actorUserId, array $context = []): void {}
        };
        $handler = new McpGovernanceHandler(new \NHK\Core\Application\Governance\GovernanceService($repository, $audit));
        $fingerprint = str_repeat('a', 64);

        $result = (new GovernedAuthorityPlanExecutor($handler))->execute(
            ['reuse' => [], 'create_candidates' => [[
                'candidate_id' => 'candidate-brand', 'action' => 'CREATE', 'entity_type' => 'brand',
                'proposed_canonical_name' => 'Hermle', 'stable_key_preview' => 'nhk:brand:hermle', 'dependencies' => [],
            ]], 'update_candidates' => [], 'relation_candidates' => []],
            $fingerprint,
            $fingerprint,
            ['candidate-brand'],
            ConversationalAuthorityPolicy::REVIEW_REQUIRED,
            '42',
            ['capture_id' => '01999999-9999-7999-8999-999999999999', 'policy_mode' => 'PROJECT_BUILD', 'approval_mode' => 'REVIEW_REQUIRED'],
        );

        $proposal = $repository->find($result['proposal_ids'][0]);
        self::assertNotNull($proposal);
        self::assertSame('01999999-9999-7999-8999-999999999999', $proposal->payload['project_build_audit']['capture_id']);
        self::assertSame('PROJECT_BUILD', $proposal->payload['project_build_audit']['policy_mode']);
        self::assertSame('42', $proposal->payload['project_build_audit']['actor']);
        self::assertCount(2, $audit->events);
    }

    public function test_controlled_apply_reuses_governance_audit_and_records_readback_context(): void
    {
        $repository = new InMemoryProposalRepository();
        $proposalId = UuidCodec::newV7();
        $resultUuid = UuidCodec::newV7();
        $repository->create(new Proposal(
            $proposalId,
            'brand',
            'create',
            ['project_build_audit' => ['capture_id' => '01999999-9999-7999-8999-999999999999', 'policy_mode' => 'PROJECT_BUILD', 'plan_fingerprint' => str_repeat('b', 64), 'approval_mode' => 'REVIEW_REQUIRED']],
            str_repeat('c', 64),
            null,
            str_repeat('d', 64),
            ProposalState::APPROVED,
            actor: '42',
            idempotencyKey: 'audit-project-build',
            targetUuid: null,
            entityType: 'brand',
        ));
        $attempts = new InMemoryApplyAttempts();
        $audit = new RecordingGovernanceAuditSink();
        $apply = new ControlledApplyService(
            $repository,
            $attempts,
            new NoopTransactionManager(),
            static fn (): string => $resultUuid,
            $audit,
            readBack: new \NHK\Core\Application\Governance\CanonicalApplyReadBackVerifier(static fn (string $type, string $id): array => ['entity_type' => $type, 'canonical_id' => $id, 'active' => true, 'revision' => 2, 'snapshot' => []]),
        );

        $result = $apply->apply($proposalId);
        $success = array_values(array_filter($audit->contexts, static fn (array $event): bool => $event[0] === 'ApplySucceeded'))[0][4];
        self::assertSame($resultUuid, $success['target_uuid']);
        self::assertSame('PROJECT_BUILD', $success['policy_mode']);
        self::assertSame(2, $success['resulting_revision']);
        self::assertSame('VERIFIED', $success['canonical_readback_outcome']);
        self::assertSame($resultUuid, $result['canonical_id']);
    }
}

final class RecordingGovernanceAuditSink implements GovernanceAuditSink
{
    public array $contexts = [];
    public function record(string $event, Proposal $proposal): void {}
    public function recordEvent(string $eventType, string $objectType, string $objectKey, ?int $actorUserId, array $context = []): void { $this->contexts[] = [$eventType, $objectType, $objectKey, $actorUserId, $context]; }
}

final class InMemoryApplyAttempts implements ApplyAttemptRepository
{
    public array $items = [];
    public function nextAttemptNumberLocked(string $proposalId): int { return count(array_filter($this->items, static fn (ApplyAttempt $attempt): bool => $attempt->proposalId === $proposalId)) + 1; }
    public function createRunning(ApplyAttempt $attempt): ApplyAttempt { return $this->items[$attempt->id] = $attempt; }
    public function markSucceeded(string $attemptId, ?string $resultEntityUuid): ApplyAttempt { $old = $this->items[$attemptId]; return $this->items[$attemptId] = new ApplyAttempt($old->id, $old->proposalId, $old->number, 'succeeded', $resultEntityUuid, finishedAt: 'now'); }
    public function persistFailed(ApplyAttempt $attempt): ApplyAttempt { return $this->items[$attempt->id] = $attempt; }
    public function findByProposal(string $proposalId): array { return array_values(array_filter($this->items, static fn (ApplyAttempt $attempt): bool => $attempt->proposalId === $proposalId)); }
    public function findSuccessful(string $proposalId): ?ApplyAttempt { foreach ($this->findByProposal($proposalId) as $attempt) if ($attempt->state === 'succeeded') return $attempt; return null; }
}

final class NoopTransactionManager implements TransactionManager
{
    public function begin(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function transactional(callable $callback): mixed { return $callback(); }
    public function run(callable $callback): mixed { return $callback(); }
}
