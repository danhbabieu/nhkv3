<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Capture\{ClockTypeMembershipPlanner, ClockTypeShadowCandidate, ClockTypeShadowResolution};
use NHK\Core\Application\Graph\{ClassifiedAsPolicy, ClockTypeDerivedRelationshipQuery, GraphService};
use NHK\Core\Application\Governance\{AuthorityProposalExecutor, CanonicalApplyReadBackVerifier, CanonicalGovernanceActionPort, CanonicalGovernedLifecycleAdapter, ClockTypeMembershipGovernanceService, ControlledApplyService, GovernanceService, ProposalEligibilityService};
use NHK\Core\Contracts\Governance\{ApplyAttemptRepository, DependencyRepository, EligibilityReader, GovernedLifecycle, GovernanceActionPort, ProposalRepository};
use NHK\Core\Contracts\Shared\TransactionManager;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, EntityTypeRegistry};
use NHK\Core\Domain\Governance\{ApplyAttempt, DependencyGraph, Proposal, ProposalState};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Graph\GraphEdge;
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class ClockTypeMembershipPr5Test extends TestCase
{
    public function test_planner_qualifies_explicit_variant_without_writing_and_is_brand_independent(): void
    {
        [$authority, $graph, $ids] = $this->fixture();
        $planner = new ClockTypeMembershipPlanner($authority, $graph);
        $result = $planner->plan($this->shadow($ids['type'], 'EXPLICIT_USER_CLOCK_TYPE_CANDIDATE'), ['type' => 'variant', 'id' => $ids['variant'], 'revision' => 1], ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1]);

        self::assertSame('QUALIFIED', $result->status);
        self::assertSame('classified_as', $result->candidate?->toArray()['predicate']);
        self::assertSame([], $result->toArray()['writes']);
        self::assertNull($graph->findEdge(new NodeReference('variant', $ids['variant']), 'classified_as', new NodeReference('classification', $ids['type'])));
    }

    public function test_planner_reuses_active_edge_and_does_not_resurrect_retired_edge(): void
    {
        [$authority, $graph, $ids] = $this->fixture();
        $source = new NodeReference('variant', $ids['variant']); $target = new NodeReference('classification', $ids['type']);
        $edge = $graph->create($source, 'classified_as', $target);
        $planner = new ClockTypeMembershipPlanner($authority, $graph);
        self::assertSame('ALREADY_CANONICAL', $planner->plan($this->shadow($ids['type']), ['type' => 'variant', 'id' => $ids['variant'], 'revision' => 1])->status);
        $graph->retire($edge->edge_uuid, 1);
        self::assertSame('BLOCKED', $planner->plan($this->shadow($ids['type']), ['type' => 'variant', 'id' => $ids['variant'], 'revision' => 1])->status);
    }

    public function test_planner_rejects_disallowed_sources_wrong_family_legacy_and_ambiguous_candidates(): void
    {
        [$authority, $graph, $ids, , , $graphRepo] = $this->fixture();
        $planner = new ClockTypeMembershipPlanner($authority, $graph);
        self::assertSame('BLOCKED', $planner->plan($this->shadow($ids['type']), ['type' => 'brand', 'id' => $ids['brand']])->status);
        self::assertSame('BLOCKED', $planner->plan($this->shadow($ids['type']), ['type' => 'movement', 'id' => $ids['brand']])->status);
        self::assertSame('BLOCKED', $planner->plan($this->shadow($ids['case']), ['type' => 'variant', 'id' => $ids['variant']])->status);
        self::assertContains('LEGACY_CLOCK_TYPE_FAMILY_NOT_WRITEABLE', $planner->plan($this->shadow($ids['legacy'], 'EXPLICIT_USER_CLOCK_TYPE_CANDIDATE', 'COMPATIBILITY_READ'), ['type' => 'variant', 'id' => $ids['variant']])->blockers);
        $ambiguous = new ClockTypeShadowResolution(ClockTypeShadowResolution::AMBIGUOUS, [$this->shadowCandidate($ids['type']), $this->shadowCandidate($ids['case'])], null, ['EXACT_CANONICAL_CONTEXT'], ['MULTIPLE'], [] , ['id' => $ids['variant'], 'type' => 'variant']);
        self::assertSame('REVIEW_REQUIRED', $planner->plan($ambiguous, ['type' => 'variant', 'id' => $ids['variant']])->status);
    }

    public function test_stale_source_and_target_revisions_fail_closed(): void
    {
        [$authority, $graph, $ids] = $this->fixture(); $planner = new ClockTypeMembershipPlanner($authority, $graph);
        self::assertContains('SOURCE_REVISION_CHANGED', $planner->plan($this->shadow($ids['type']), ['type' => 'variant', 'id' => $ids['variant'], 'revision' => 2])->blockers);
        self::assertContains('TARGET_REVISION_CHANGED', $planner->plan($this->shadow($ids['type'], 'EXPLICIT_CANONICAL_ID', 'RESOLVED', 2), ['type' => 'variant', 'id' => $ids['variant']])->blockers);
        self::assertContains('TARGET_FAMILY_NOT_CLOCK_TYPE', $planner->plan($this->shadow($ids['case']), ['type' => 'variant', 'id' => $ids['variant']])->blockers);
    }

    public function test_governed_create_approval_controlled_apply_and_exact_readback(): void
    {
        [$authority, $graph, $ids, $types, $endpoints, $graphRepo] = $this->fixture(true);
        $proposals = new Pr5ProposalRepository(); $governance = new GovernanceService($proposals);
        $eligibility = new ProposalEligibilityService($proposals, new DependencyGraph(new Pr5DependencyRepository()), new Pr5EligibilityReader($authority, $graphRepo));
        $readBack = new CanonicalApplyReadBackVerifier(static function (string $type, string $id) use ($graphRepo): ?array {
            $edge = $type === 'relation' ? $graphRepo->findByUuid($id) : null;
            return $edge instanceof GraphEdge ? ['entity_type' => 'relation', 'canonical_id' => $edge->edge_uuid, 'active' => $edge->isActive(), 'revision' => $edge->revision, 'snapshot' => get_object_vars($edge)] : null;
        });
        $executor = new AuthorityProposalExecutor(new AuthorityService($authority, $types), $graph);
        $controlled = new ControlledApplyService($proposals, new Pr5ApplyAttemptRepository(), new Pr5TransactionManager(), $executor, readBack: $readBack, eligibility: $eligibility);
        $actions = new CanonicalGovernanceActionPort($proposals, $governance, $eligibility, $controlled);
        $lifecycle = new CanonicalGovernedLifecycleAdapter($governance, $eligibility);
        $service = new ClockTypeMembershipGovernanceService($lifecycle, $actions, $graph, $authority);
        $candidate = (new ClockTypeMembershipPlanner($authority, $graph))->plan($this->shadow($ids['type']), ['type' => 'specimen', 'id' => $ids['specimen'], 'revision' => 1], ['provenance_class' => 'OBSERVED_FROM_MEDIA'])->candidate;
        self::assertNotNull($candidate);
        $proposal = $service->createAndSubmit($candidate);
        self::assertSame(ProposalState::SUBMITTED, $proposal->state);
        $applied = $service->approveAndApply($proposal->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, 'owner');
        self::assertTrue($applied['canonical_relation_readback']['active']);
        self::assertSame('classified_as', $applied['canonical_relation_readback']['predicate']);
        self::assertSame(1, count($graphRepo->allEdges(false)));
    }

    public function test_brand_and_clock_type_projection_is_derived_and_has_no_shortcut_edge(): void
    {
        [$authority, $graph, $ids, , , $graphRepo] = $this->fixture();
        $graph->create(new NodeReference('model', $ids['model']), 'model_of', new NodeReference('brand', $ids['brand']));
        $graph->create(new NodeReference('variant', $ids['variant']), 'variant_of', new NodeReference('model', $ids['model']));
        $graph->create(new NodeReference('variant', $ids['variant']), 'classified_as', new NodeReference('classification', $ids['type']));
        $query = new \NHK\Core\Application\Graph\ClockTypeDerivedRelationshipQuery($graph, $authority);
        $brand = $query->forBrand($ids['brand']); $type = $query->forClockType($ids['type']);
        self::assertSame('AVAILABLE_WITH_ITEMS', $brand['status']); self::assertSame($ids['type'], $brand['items'][0]['canonical_id']);
        self::assertSame('DERIVED', $brand['items'][0]['relationship_class']); self::assertCount(3, $brand['items'][0]['best_path']);
        self::assertSame($ids['brand'], $type['items'][0]['canonical_id']); self::assertSame('DERIVED', $type['items'][0]['relationship_class']);
        self::assertNull($graph->findEdge(new NodeReference('brand', $ids['brand']), 'classified_as', new NodeReference('classification', $ids['type'])));
    }

    /** @return array{0:InMemoryAuthorityRepository,1:GraphService,2:array<string,string>,3:EntityTypeRegistry,4:EndpointTypeRegistry,5:InMemoryGraphRepository} */
    private function fixture(bool $includeSpecimen = false): array
    {
        $authority = new InMemoryAuthorityRepository(); $ids = [];
        foreach (['brand', 'model', 'variant', 'specimen'] as $type) $ids[$type] = UuidCodec::newV7();
        $ids['type'] = UuidCodec::newV7(); $ids['case'] = UuidCodec::newV7(); $ids['legacy'] = UuidCodec::newV7();
        $authority->create(new AuthorityEntity($ids['brand'], 'brand', 'nhk:brand:pr5', 'Odo', 1, []));
        $authority->create(new AuthorityEntity($ids['model'], 'model', 'nhk:model:pr5', 'Odo Model', 1, []));
        $authority->create(new AuthorityEntity($ids['variant'], 'variant', 'nhk:variant:pr5', 'Odo Variant', 1, []));
        $authority->create(new AuthorityEntity($ids['specimen'], 'specimen', 'nhk:specimen:pr5', 'Specimen', 1, []));
        $authority->create(new AuthorityEntity($ids['type'], 'classification', 'nhk:classification:clock-type.pr5', 'Đồng hồ vai bò', 1, ['family' => 'clock_type']));
        $authority->create(new AuthorityEntity($ids['case'], 'classification', 'nhk:classification:case-form.pr5', 'Dáng vai bò', 1, ['family' => 'case_form']));
        $authority->create(new AuthorityEntity($ids['legacy'], 'classification', 'nhk:classification:legacy.pr5', 'Legacy Clock Type', 1, ['family' => 'clock-type']));
        $types = new EntityTypeRegistry(); $endpoints = new EndpointTypeRegistry();
        foreach (['brand', 'model', 'variant', 'specimen', 'classification'] as $type) { $endpoints->register($type, new FakeEndpointResolver($type, array_values(array_filter($ids, static fn (string $id, string $key): bool => $key === $type || ($type === 'classification' && in_array($key, ['type', 'case', 'legacy'], true)), ARRAY_FILTER_USE_BOTH)))); }
        $repo = new InMemoryGraphRepository();
        $graph = new GraphService($repo, $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());
        return [$authority, $graph, $ids, $types, $endpoints, $repo];
    }

    private function shadow(string $id, string $basis = 'EXPLICIT_CANONICAL_ID', string $profileStatus = 'RESOLVED', int $revision = 1): ClockTypeShadowResolution
    {
        return new ClockTypeShadowResolution(ClockTypeShadowResolution::RESOLVED_EXPLICIT, [$this->shadowCandidate($id, $basis, $profileStatus, $revision)], $this->shadowCandidate($id, $basis, $profileStatus, $revision), [$basis], [], [], ['id' => UuidCodec::newV7(), 'type' => 'variant']);
    }

    private function shadowCandidate(string $id, string $basis = 'EXPLICIT_CANONICAL_ID', string $profileStatus = 'RESOLVED', int $revision = 1): ClockTypeShadowCandidate
    {
        return new ClockTypeShadowCandidate($id, 'nhk:classification:pr5', 'Candidate', $profileStatus === 'COMPATIBILITY_READ' ? 'clock-type' : 'clock_type', 'clock_type', $basis, 'SHADOW_REVIEW_REQUIRED', 'CAPTURE', $profileStatus, $revision);
    }
}

final class Pr5ProposalRepository implements ProposalRepository
{
    private array $items = []; private array $approvals = [];
    public function create(Proposal $p): Proposal { return $this->items[$p->id] = $p; }
    public function find(string $id): ?Proposal { return $this->items[$id] ?? null; }
    public function findByIdempotencyKey(string $key): ?Proposal { foreach ($this->items as $p) if ($p->idempotencyKey === $key) return $p; return null; }
    public function save(Proposal $p): Proposal { return $this->items[$p->id] = $p; }
    public function findForUpdate(string $id): ?Proposal { return $this->find($id); }
    public function recordApproval(Proposal $p, string $actor): void { $this->approvals[$p->id] = ['proposal_revision' => $p->revision, 'fingerprint' => $p->bindingFingerprint()]; }
    public function latestApproval(string $id): ?array { return $this->approvals[$id] ?? null; }
    public function findLatestVideoIngest(string $id): ?Proposal { return null; }
}

final class Pr5DependencyRepository implements DependencyRepository
{ public function directDependencies(string $proposalId): array { return []; } public function add(string $proposalId, string $dependencyUuid): void {} }

final class Pr5EligibilityReader implements EligibilityReader
{
    public function __construct(private InMemoryAuthorityRepository $authority, private InMemoryGraphRepository $graph) {}
    public function isApplied(string $dependencyUuid): bool { return true; }
    public function targetRevision(string $targetUuid): ?int { return $this->authority->findByCanonicalId($targetUuid)?->revision ?? $this->graph->findByUuid($targetUuid)?->revision; }
    public function targetExists(string $targetUuid): bool { return $this->targetRevision($targetUuid) !== null; }
}

final class Pr5ApplyAttemptRepository implements ApplyAttemptRepository
{
    private array $items = [];
    public function nextAttemptNumberLocked(string $proposalId): int { return count($this->findByProposal($proposalId)) + 1; }
    public function createRunning(ApplyAttempt $a): ApplyAttempt { return $this->items[$a->id] = $a; }
    public function markSucceeded(string $id, ?string $resultEntityUuid): ApplyAttempt { $a = $this->items[$id]; return $this->items[$id] = new ApplyAttempt($a->id, $a->proposalId, $a->number, 'succeeded', $resultEntityUuid, startedAt: $a->startedAt, finishedAt: 'now'); }
    public function persistFailed(ApplyAttempt $a): ApplyAttempt { return $this->items[$a->id] = $a; }
    public function findByProposal(string $id): array { return array_values(array_filter($this->items, static fn (ApplyAttempt $a): bool => $a->proposalId === $id)); }
    public function findSuccessful(string $id): ?ApplyAttempt { foreach ($this->findByProposal($id) as $a) if ($a->state === 'succeeded') return $a; return null; }
}

final class Pr5TransactionManager implements TransactionManager
{ public function begin(): void {} public function commit(): void {} public function rollback(): void {} public function transactional(callable $callback): mixed { return $callback(); } public function run(callable $callback): mixed { return $callback(); } }
