<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\{CanonicalApplyReadBackVerifier, ControlledApplyService, GovernedAuthorityPlanExecutor, GovernanceService, ProposalEligibilityService};
use NHK\Core\Application\Mcp\McpGovernanceHandler;
use NHK\Core\Application\Graph\ExplicitRelationIntentPlanner;
use NHK\Core\Contracts\Governance\{ApplyAttemptRepository, DependencyRepository, EligibilityReader, ProposalRepository};
use NHK\Core\Contracts\Shared\TransactionManager;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, GraphEdge, NodeReference, PredicateRegistry};
use NHK\Core\Contracts\Graph\EndpointRevisionReader;
use NHK\Core\Domain\Governance\{ApplyAttempt, ConversationalAuthorityPolicy, DependencyGraph, Proposal, ProposalState};
use NHK\Core\Application\Authority\AuthorityService;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class TypedRelationGovernanceLifecycleTest extends TestCase
{
    public function test_typed_relation_crosses_proposal_approval_eligibility_apply_and_graph_readback(): void
    {
        $source = '01a07cbc-3595-7e63-8c1b-5b308c644125';
        $target = '01a08156-c400-7739-a40f-61185cd62fcd';
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('classification', new LifecycleEndpointResolver('classification', [$source]));
        $endpoints->register('knowledge', new LifecycleEndpointResolver('knowledge', [$target]));
        $planner = new ExplicitRelationIntentPlanner(
            $endpoints,
            new PredicateRegistry(),
            static fn (NodeReference $reference): array => ['active' => true, 'revision' => 1],
            static fn (array $packet): array => [],
        );
        $candidate = $planner->plan([['source_type' => 'classification', 'source_uuid' => $source, 'predicate' => 'about', 'target_type' => 'knowledge', 'target_uuid' => $target]])['relation_candidates'][0];

        $proposalRepository = new LifecycleProposalRepository();
        $governance = new GovernanceService($proposalRepository);
        $graphRepository = new InMemoryGraphRepository();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepository = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepository, $types);
        $graph = new \NHK\Core\Application\Graph\GraphService($graphRepository, $endpoints, new PredicateRegistry(), new \NHK\Core\Infrastructure\Graph\InMemoryAuditSink());
        $reader = new LifecycleEligibilityReader([$source => 1, $target => 1]);
        $eligibility = new ProposalEligibilityService($proposalRepository, new DependencyGraph(new EmptyLifecycleDependencyRepository()), $reader);
        $readback = new CanonicalApplyReadBackVerifier(static function (string $type, string $id) use ($graphRepository): ?array {
            $edge = $type === 'relation' ? $graphRepository->findByUuid($id) : null;
            return $edge instanceof GraphEdge ? ['entity_type' => 'relation', 'canonical_id' => $edge->edge_uuid, 'active' => $edge->isActive(), 'revision' => $edge->revision, 'snapshot' => get_object_vars($edge)] : null;
        });
        $controlled = new ControlledApplyService($proposalRepository, new LifecycleAttemptRepository(), new LifecycleTransactionManager(), new \NHK\Core\Application\Governance\AuthorityProposalExecutor($authority, $graph), readBack: $readback, eligibility: $eligibility);
        $handler = new McpGovernanceHandler($governance, $eligibility, $controlled, null, $endpoints);
        $plan = ['relation_candidates' => [$candidate], 'relation_reuse' => [], 'reuse' => [], 'create_candidates' => [], 'update_candidates' => []];
        self::assertNotEmpty($candidate['candidate_id']);
        $result = (new GovernedAuthorityPlanExecutor($handler))->execute($plan, str_repeat('a', 64), str_repeat('a', 64), [$candidate['candidate_id']], ConversationalAuthorityPolicy::AUTO_APPROVE_AFTER_OWNER_CONFIRMATION);

        self::assertSame('APPLIED', $result['status']);
        self::assertCount(1, $result['proposal_ids']);
        self::assertSame('relation', $result['apply_results'][0]['canonical_readback']['entity_type']);
        $edge = $graph->findEdge(new NodeReference('classification', $source), 'about', new NodeReference('knowledge', $target));
        self::assertNotNull($edge);
        self::assertTrue($edge->isActive());
    }
}

final class LifecycleProposalRepository implements ProposalRepository
{
    private array $items = [];
    private array $approvals = [];
    public function create(Proposal $proposal): Proposal { return $this->items[$proposal->id] = $proposal; }
    public function find(string $id): ?Proposal { return $this->items[$id] ?? null; }
    public function findByIdempotencyKey(string $key): ?Proposal { foreach ($this->items as $item) if ($item->idempotencyKey === $key) return $item; return null; }
    public function save(Proposal $proposal): Proposal { return $this->items[$proposal->id] = $proposal; }
    public function findForUpdate(string $id): ?Proposal { return $this->find($id); }
    public function recordApproval(Proposal $proposal, string $actor): void { $this->approvals[$proposal->id] = ['proposal_revision' => $proposal->revision, 'fingerprint' => $proposal->bindingFingerprint()]; }
    public function latestApproval(string $proposalId): ?array { return $this->approvals[$proposalId] ?? null; }
    public function findLatestVideoIngest(string $videoId): ?Proposal { return null; }
}

final class EmptyLifecycleDependencyRepository implements DependencyRepository
{
    public function directDependencies(string $proposalId): array { return []; }
    public function add(string $proposalId, string $dependencyUuid): void {}
}

final class LifecycleEligibilityReader implements EligibilityReader
{
    public function __construct(private array $revisions) {}
    public function isApplied(string $dependencyUuid): bool { return true; }
    public function targetRevision(string $targetUuid): ?int { return $this->revisions[$targetUuid] ?? null; }
    public function targetExists(string $targetUuid): bool { return isset($this->revisions[$targetUuid]); }
}

final class LifecycleAttemptRepository implements ApplyAttemptRepository
{
    private array $items = [];
    public function nextAttemptNumberLocked(string $proposalId): int { return count($this->findByProposal($proposalId)) + 1; }
    public function createRunning(ApplyAttempt $attempt): ApplyAttempt { return $this->items[$attempt->id] = $attempt; }
    public function markSucceeded(string $attemptId, ?string $resultEntityUuid): ApplyAttempt { $old = $this->items[$attemptId]; return $this->items[$attemptId] = new ApplyAttempt($old->id, $old->proposalId, $old->number, 'succeeded', $resultEntityUuid); }
    public function persistFailed(ApplyAttempt $attempt): ApplyAttempt { return $this->items[$attempt->id] = $attempt; }
    public function findByProposal(string $proposalId): array { return array_values(array_filter($this->items, static fn (ApplyAttempt $item): bool => $item->proposalId === $proposalId)); }
    public function findSuccessful(string $proposalId): ?ApplyAttempt { foreach ($this->findByProposal($proposalId) as $item) if ($item->state === 'succeeded') return $item; return null; }
}

final class LifecycleTransactionManager implements TransactionManager
{
    public function begin(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function transactional(callable $callback): mixed { return $callback(); }
    public function run(callable $callback): mixed { return $callback(); }
}

final class LifecycleEndpointResolver implements EndpointRevisionReader
{
    public function __construct(private string $type, private array $keys) {}
    public function supports(string $endpoint_type): bool { return $endpoint_type === $this->type; }
    public function normalize(NodeReference $reference): NodeReference { return new NodeReference($this->type, strtolower(trim($reference->endpoint_key))); }
    public function exists(NodeReference $reference): bool { return in_array($reference->endpoint_key, $this->keys, true); }
    public function revision(NodeReference $reference): ?int { return $this->exists($reference) ? 1 : null; }
}
