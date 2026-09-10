<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Governance\{CanonicalGovernanceActionPort, ControlledApplyService, GovernanceService, ProposalEligibilityService};
use NHK\Core\Application\Media\MediaService;
use NHK\Core\Contracts\Governance\{ApplyAttemptRepository, DependencyRepository, EligibilityReader};
use NHK\Core\Contracts\Shared\TransactionManager;
use NHK\Core\Domain\Governance\{ApplyAttempt, DependencyGraph, Proposal, ProposalState};
use NHK\Core\Infrastructure\Governance\GovernanceRuntime;
use NHK\Core\Infrastructure\Governance\GovernanceRuntimeFactory;
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository, WordPressMediaAttachmentBridge};
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class GovernanceActionPortTest extends TestCase
{
    private const ID = '018f2f1e-7b2c-7abc-8def-0123456789ab';
    private const RESULT = '018f2f1e-7b2c-7abc-8def-0123456789ac';

    public function test_canonical_port_delegates_approval_and_rejection_to_governance_service(): void
    {
        $port = $this->portWithDraftProposal();

        self::assertSame(ProposalState::APPROVED, $port->approve(self::ID, 'content', 'dependency', '7')->state);

        $rejected = $this->portWithDraftProposal()->reject(self::ID, '7');
        self::assertSame(ProposalState::REJECTED, $rejected->state);
    }

    public function test_canonical_port_delegates_find_submit_and_eligibility(): void
    {
        $port = $this->portWithDraftProposal();

        self::assertSame(self::ID, $port->find(self::ID)?->id);
        self::assertSame(ProposalState::SUBMITTED, $port->submit(self::ID)->state);
        self::assertFalse($port->eligibility(self::ID)->ready);
        self::assertSame(['APPROVAL_MISSING'], $port->eligibility(self::ID)->reasons);
    }

    public function test_canonical_port_delegates_controlled_apply_and_returns_canonical_result(): void
    {
        $port = $this->portWithApprovedProposal();

        $result = $port->apply(self::ID);

        self::assertFalse($result['idempotent']);
        self::assertSame(self::RESULT, $result['canonical_id']);
        self::assertSame(ProposalState::APPLIED, $port->find(self::ID)?->state);
    }

    public function test_runtime_is_a_readonly_container_for_the_canonical_governance_services(): void
    {
        $repository = new InMemoryProposalRepository();
        $governance = new GovernanceService($repository);
        $eligibility = new ProposalEligibilityService($repository, new DependencyGraph(new EmptyDependencyRepository()), new ReadyEligibilityReader());
        $apply = new ControlledApplyService($repository, new InMemoryApplyAttemptRepository(), new InlineTransactionManager(), static fn (): string => self::RESULT);
        $runtime = new GovernanceRuntime($repository, $governance, $eligibility, $apply);

        self::assertSame($repository, $runtime->proposals);
        self::assertSame($governance, $runtime->governance);
        self::assertSame($eligibility, $runtime->eligibility);
        self::assertSame($apply, $runtime->controlledApply);
    }

    public function test_admin_action_port_contains_no_persistence_or_sql_bypass(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Application/Governance/CanonicalGovernanceActionPort.php');
        self::assertIsString($source);
        self::assertDoesNotMatchRegularExpression('/\$wpdb->query|INSERT|UPDATE|DELETE|->save\(|->create\(/i', $source);
    }

    public function test_runtime_factory_builds_the_canonical_runtime_from_wordpress_database(): void
    {
        $runtime = GovernanceRuntimeFactory::fromWordPress(new \stdClass());

        self::assertInstanceOf(GovernanceRuntime::class, $runtime);
        self::assertInstanceOf(GovernanceService::class, $runtime->governance);
        self::assertInstanceOf(ProposalEligibilityService::class, $runtime->eligibility);
        self::assertInstanceOf(ControlledApplyService::class, $runtime->controlledApply);
    }

    public function test_runtime_factory_uses_the_shared_wordpress_attachment_bridge_for_controlled_apply(): void
    {
        $wpdb = new \stdClass();
        $media = new WpdbMediaRepository($wpdb);
        $assets = new WpdbMediaAssetRepository($wpdb);
        $sharedBridge = new WordPressMediaAttachmentBridge($wpdb, new MediaService($media, $assets, new WpdbMediaUsageRepository($wpdb)), $media, $assets);

        $runtime = GovernanceRuntimeFactory::fromWordPress($wpdb, $sharedBridge);
        $controlledApply = new \ReflectionObject($runtime->controlledApply);
        $executorProperty = $controlledApply->getProperty('executor');
        $executorProperty->setAccessible(true);
        $executor = $executorProperty->getValue($runtime->controlledApply);
        $executorReflection = new \ReflectionObject($executor);
        $gatewayProperty = $executorReflection->getProperty('mediaGateway');
        $gatewayProperty->setAccessible(true);
        $gateway = $gatewayProperty->getValue($executor);
        $gatewayReflection = new \ReflectionObject($gateway);
        $bridgeProperty = $gatewayReflection->getProperty('wordpress');
        $bridgeProperty->setAccessible(true);

        self::assertSame($sharedBridge, $bridgeProperty->getValue($gateway));
    }

    private function portWithDraftProposal(): CanonicalGovernanceActionPort
    {
        $repository = new InMemoryProposalRepository();
        $repository->create(new Proposal(self::ID, 'subject', 'create', [], 'content', 1, 'dependency', idempotencyKey: 'test'));
        return new CanonicalGovernanceActionPort(
            $repository,
            new GovernanceService($repository),
            new ProposalEligibilityService($repository, new DependencyGraph(new EmptyDependencyRepository()), new ReadyEligibilityReader()),
            new ControlledApplyService($repository, new InMemoryApplyAttemptRepository(), new InlineTransactionManager(), static fn (): string => self::RESULT),
        );
    }

    private function portWithApprovedProposal(): CanonicalGovernanceActionPort
    {
        $repository = new InMemoryProposalRepository();
        $proposal = new Proposal(self::ID, 'subject', 'create', [], 'content', 1, 'dependency', state: ProposalState::APPROVED, decisionActor: '7', idempotencyKey: 'test');
        $repository->create($proposal);
        $repository->recordApproval($proposal, '7');
        return new CanonicalGovernanceActionPort(
            $repository,
            new GovernanceService($repository),
            new ProposalEligibilityService($repository, new DependencyGraph(new EmptyDependencyRepository()), new ReadyEligibilityReader()),
            new ControlledApplyService($repository, new InMemoryApplyAttemptRepository(), new InlineTransactionManager(), static fn (): string => self::RESULT, eligibility: null),
        );
    }
}

final class EmptyDependencyRepository implements DependencyRepository
{
    public function directDependencies(string $proposalId): array { return []; }
    public function add(string $proposalId, string $dependencyUuid): void {}
}

final class ReadyEligibilityReader implements EligibilityReader
{
    public function isApplied(string $dependencyUuid): bool { return true; }
    public function targetRevision(string $targetUuid): ?int { return 1; }
    public function targetExists(string $targetUuid): bool { return true; }
}

final class InlineTransactionManager implements TransactionManager
{
    public function begin(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function transactional(callable $callback): mixed { return $callback(); }
    public function run(callable $callback): mixed { return $callback(); }
}

final class InMemoryApplyAttemptRepository implements ApplyAttemptRepository
{
    /** @var array<string, ApplyAttempt> */
    private array $items = [];

    public function nextAttemptNumberLocked(string $proposalId): int { return count($this->findByProposal($proposalId)) + 1; }
    public function createRunning(ApplyAttempt $attempt): ApplyAttempt { return $this->items[$attempt->id] = $attempt; }
    public function markSucceeded(string $attemptId, ?string $resultEntityUuid): ApplyAttempt
    {
        $attempt = $this->items[$attemptId];
        return $this->items[$attemptId] = new ApplyAttempt($attempt->id, $attempt->proposalId, $attempt->number, 'succeeded', $resultEntityUuid, startedAt: $attempt->startedAt, finishedAt: 'now');
    }
    public function persistFailed(ApplyAttempt $attempt): ApplyAttempt { return $this->items[$attempt->id] = $attempt; }
    public function findByProposal(string $proposalId): array { return array_values(array_filter($this->items, static fn (ApplyAttempt $item): bool => $item->proposalId === $proposalId)); }
    public function findSuccessful(string $proposalId): ?ApplyAttempt { foreach ($this->findByProposal($proposalId) as $item) if ($item->state === 'succeeded') return $item; return null; }
}
