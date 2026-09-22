<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\RelationshipReadService;
use NHK\Core\Application\Mcp\{McpToolCatalog, McpDispatchRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Application\Graph\{EvidenceRelationshipAdapter, MediaUsageRelationshipAdapter};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class RelationshipReadServiceTest extends TestCase
{
    private string $model;
    private string $brand;
    private EndpointTypeRegistry $endpoints;
    private PredicateRegistry $predicates;
    private InMemoryGraphRepository $repository;
    private RelationshipReadService $service;

    protected function setUp(): void
    {
        $this->model = '11111111-1111-4111-8111-111111111111';
        $this->brand = '22222222-2222-4222-8222-222222222222';
        $this->endpoints = new EndpointTypeRegistry();
        foreach (['model' => [$this->model], 'brand' => [$this->brand], 'classification' => [], 'media' => []] as $type => $ids) $this->endpoints->register($type, new FakeEndpointResolver($type, $ids));
        $this->predicates = new PredicateRegistry();
        $this->repository = new InMemoryGraphRepository();
        $this->service = new RelationshipReadService($this->endpoints, $this->predicates, $this->repository, static function (NodeReference $reference): array { return ['exists' => true, 'active' => true, 'revision' => 1, 'family' => $reference->endpoint_type === 'classification' ? 'clock_type' : null]; });
    }

    public function test_registry_is_a_projection_of_executable_registries_and_hash_is_deterministic(): void
    {
        $first = $this->service->registry();
        $second = $this->service->registry();
        self::assertSame($first, $second);
        self::assertCount(count($this->endpoints->all()), $first['endpoints']);
        self::assertCount(count($this->predicates->all()), $first['predicates']);
        $registeredTypes = array_keys($this->endpoints->all()); sort($registeredTypes);
        self::assertSame($registeredTypes, array_column($first['endpoints'], 'type'));
        $registeredPredicates = array_map(static fn ($rule): string => $rule->key, $this->predicates->all()); sort($registeredPredicates);
        self::assertSame($registeredPredicates, array_column($first['predicates'], 'predicate'));
    }

    public function test_preview_add_is_read_only_and_returns_deterministic_plan(): void
    {
        $before = $this->repository->allEdges();
        $result = $this->service->preview(['operation' => 'ADD', 'source' => ['type' => 'model', 'id' => $this->model], 'target' => ['type' => 'brand', 'id' => $this->brand], 'predicate' => 'model_of', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']);
        self::assertTrue($result['safe_to_apply']);
        self::assertSame('CREATE_OR_REACTIVATE', $result['planned_transition'][0]['action']);
        self::assertSame($before, $this->repository->allEdges());
    }

    public function test_preview_add_of_exact_active_triple_is_idempotent_no_op_with_relation_read_back(): void
    {
        $edge = $this->repository->createEdge($this->repository->resolveNode(new NodeReference('model', $this->model)), $this->predicates->get('model_of'), $this->repository->resolveNode(new NodeReference('brand', $this->brand)));
        $before = $this->repository->allEdges();

        $result = $this->service->preview(['operation' => 'ADD', 'source' => ['type' => 'model', 'id' => $this->model], 'target' => ['type' => 'brand', 'id' => $this->brand], 'predicate' => 'model_of', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']);

        self::assertTrue($result['safe_to_apply']);
        self::assertSame('ACTIVE', $result['current_state']);
        self::assertSame($edge->edge_uuid, $result['planned_transition'][0]['relation_id']);
        self::assertSame($edge->revision, $result['planned_transition'][0]['current_revision']);
        self::assertSame('RELATION_NO_OP', $result['planned_transition'][0]['action']);
        self::assertSame('ALREADY_ACTIVE', $result['planned_transition'][0]['reason']);
        self::assertTrue($result['planned_transition'][0]['idempotent']);
        self::assertSame($edge->edge_uuid, $result['current_relationships'][0]['edge_uuid']);
        self::assertSame($edge->revision, $result['current_relationships'][0]['revision']);
        self::assertSame($before, $this->repository->allEdges());
    }

    public function test_invalid_endpoint_predicate_and_cardinality_fail_closed(): void
    {
        $invalid = $this->service->preview(['operation' => 'ADD', 'source' => ['type' => 'model', 'id' => $this->model], 'target' => ['type' => 'brand', 'id' => $this->brand], 'predicate' => 'not_registered']);
        self::assertContains('PREDICATE_UNREGISTERED', $invalid['blockers']);
        $wrong = $this->service->preview(['operation' => 'ADD', 'source' => ['type' => 'brand', 'id' => $this->brand], 'target' => ['type' => 'model', 'id' => $this->model], 'predicate' => 'model_of']);
        self::assertContains('SOURCE_TYPE_NOT_ALLOWED', $wrong['blockers']);
        $repository = $this->repository;
        $repository->createEdge($repository->resolveNode(new NodeReference('model', $this->model)), $this->predicates->get('model_of'), $repository->resolveNode(new NodeReference('brand', $this->brand)));
        $other = '33333333-3333-4333-8333-333333333333';
        $this->endpoints->register('brand', new FakeEndpointResolver('brand', [$this->brand, $other]));
        $result = $this->service->preview(['operation' => 'ADD', 'source' => ['type' => 'model', 'id' => $this->model], 'target' => ['type' => 'brand', 'id' => $other], 'predicate' => 'model_of']);
        self::assertContains('CARDINALITY_CONFLICT', $result['blockers']);
    }

    public function test_retired_relation_requires_explicit_reactivate_and_replace_is_retire_plus_create(): void
    {
        $edge = $this->repository->createEdge($this->repository->resolveNode(new NodeReference('model', $this->model)), $this->predicates->get('model_of'), $this->repository->resolveNode(new NodeReference('brand', $this->brand)));
        $this->repository->retire($edge, 1);
        $add = $this->service->preview(['operation' => 'ADD', 'source' => ['type' => 'model', 'id' => $this->model], 'target' => ['type' => 'brand', 'id' => $this->brand], 'predicate' => 'model_of']);
        self::assertContains('RELATION_RETIRED_REACTIVATION_REQUIRED', $add['blockers']);
        $reactivate = $this->service->preview(['operation' => 'REACTIVATE', 'source' => ['type' => 'model', 'id' => $this->model], 'target' => ['type' => 'brand', 'id' => $this->brand], 'predicate' => 'model_of']);
        self::assertSame('RELATION_REACTIVATE', $reactivate['planned_transition'][0]['action']);
        $replace = $this->service->preview(['operation' => 'REPLACE', 'current_relation_id' => $edge->edge_uuid, 'source' => ['type' => 'model', 'id' => $this->model], 'target' => ['type' => 'brand', 'id' => $this->brand], 'predicate' => 'model_of']);
        self::assertSame('RELATION_RETIRE', $replace['planned_transition'][0]['action']);
        self::assertSame('CREATE_OR_REACTIVATE', $replace['planned_transition'][1]['action']);
    }

    public function test_owner_specific_kinds_never_become_graph_edges(): void
    {
        $media = $this->service->list(['relationship_kind' => 'media_usage']);
        $evidence = $this->service->preview(['operation' => 'CREATE', 'relationship_kind' => 'evidence', 'claim_uuid' => $this->model, 'source_uuid' => $this->brand]);
        self::assertSame('MediaUsage', $media['owner']);
        self::assertContains('DEPENDENCY_NOT_FOUND', $evidence['blockers']);
        self::assertSame([], $this->repository->allEdges());
    }

    public function test_registry_exposes_owner_adapters_without_changing_graph_hash(): void
    {
        $registry = $this->service->registry();
        self::assertSame(['graph', 'media_usage', 'evidence'], array_keys($registry['relationship_kinds']));
        self::assertSame('Evidence', $registry['relationship_kinds']['evidence']['canonical_owner']);
        self::assertFalse($registry['relationship_kinds']['evidence']['graph_projection']);
        self::assertNotEmpty($registry['registry_hash']);
    }

    public function test_evidence_preview_binds_claim_and_source_revisions_and_never_writes_graph(): void
    {
        $claimId = '33333333-3333-4333-8333-333333333333';
        $sourceId = '44444444-4444-4444-8444-444444444444';
        $evidenceId = '55555555-5555-4555-8555-555555555555';
        $claims = $this->createMock(KnowledgeRepository::class);
        $sources = $this->createMock(SourceRepository::class);
        $evidence = $this->createMock(EvidenceRepository::class);
        $claims->method('findByCanonicalId')->with($claimId)->willReturn(new KnowledgeClaim($claimId, 'claim.one', 'A claim', revision: 4));
        $sources->method('findByCanonicalId')->with($sourceId)->willReturn(new Source($sourceId, 'source.one', 'A source', revision: 7));
        $evidence->method('findByCanonicalId')->with($evidenceId)->willReturn(new Evidence($evidenceId, $claimId, $sourceId, excerpt: 'Excerpt', revision: 2));
        $adapter = new EvidenceRelationshipAdapter($evidence, $claims, $sources);
        $preview = $adapter->preview(['operation' => 'UPDATE', 'evidence_uuid' => $evidenceId, 'claim_uuid' => $claimId, 'claim_revision' => 4, 'source_uuid' => $sourceId, 'source_revision' => 7, 'expected_evidence_revision' => 2]);
        self::assertTrue($preview['safe_to_apply']);
        self::assertSame([4, 7], [$preview['revision_state']['claim_revision'], $preview['revision_state']['source_revision']]);
        self::assertSame([], $this->repository->allEdges());
        $stale = $adapter->preview(['operation' => 'RETIRE', 'evidence_uuid' => $evidenceId, 'claim_uuid' => $claimId, 'claim_revision' => 3, 'source_uuid' => $sourceId, 'source_revision' => 7, 'expected_evidence_revision' => 2]);
        self::assertContains('CLAIM_REVISION_CONFLICT', $stale['blockers']);
    }

    public function test_media_usage_preview_requires_exact_usage_cas_for_replace_and_remove(): void
    {
        $adapter = new MediaUsageRelationshipAdapter();
        foreach (['REPLACE', 'REMOVE'] as $operation) {
            $result = $adapter->preview(['operation' => $operation]);
            self::assertContains('USAGE_REVISION_BINDING_REQUIRED', $result['blockers']);
            self::assertFalse($result['safe_to_apply']);
        }
        self::assertContains('USAGE_REVISION_BINDING_REQUIRED', $adapter->preview(['operation' => 'REPRESENTATIVE_BIND'])['blockers']);
    }

    public function test_subtype_cycle_and_missing_family_fail_closed(): void
    {
        $a = '44444444-4444-4444-8444-444444444444';
        $b = '55555555-5555-4555-8555-555555555555';
        $this->endpoints->register('classification', new FakeEndpointResolver('classification', [$a, $b]));
        $this->repository->createEdge($this->repository->resolveNode(new NodeReference('classification', $a)), $this->predicates->get('subtype_of'), $this->repository->resolveNode(new NodeReference('classification', $b)));
        $cycle = $this->service->preview(['operation' => 'ADD', 'source' => ['type' => 'classification', 'id' => $b], 'target' => ['type' => 'classification', 'id' => $a], 'predicate' => 'subtype_of']);
        self::assertContains('HIERARCHY_CYCLE', $cycle['blockers']);
        $missingFamily = new RelationshipReadService($this->endpoints, $this->predicates, $this->repository, static fn (NodeReference $reference): array => ['exists' => true, 'active' => true, 'revision' => 1, 'family' => null]);
        $result = $missingFamily->preview(['operation' => 'ADD', 'source' => ['type' => 'classification', 'id' => $a], 'target' => ['type' => 'classification', 'id' => $b], 'predicate' => 'subtype_of']);
        self::assertContains('CLASSIFICATION_FAMILY_UNRESOLVED', $result['blockers']);
    }

    public function test_classified_as_requires_target_family_but_not_source_family(): void
    {
        $variant = '66666666-6666-4666-8666-666666666666';
        $classification = '77777777-7777-4777-8777-777777777777';
        $this->endpoints->register('variant', new FakeEndpointResolver('variant', [$variant]));
        $this->endpoints->register('classification', new FakeEndpointResolver('classification', [$classification]));
        $state = static fn (NodeReference $reference): array => [
            'exists' => true, 'active' => true, 'revision' => 1,
            'family' => $reference->endpoint_type === 'classification' ? 'clock_type' : null,
        ];
        $service = new RelationshipReadService($this->endpoints, $this->predicates, $this->repository, $state);

        $result = $service->preview([
            'operation' => 'ADD', 'source' => ['type' => 'variant', 'id' => $variant],
            'target' => ['type' => 'classification', 'id' => $classification],
            'predicate' => 'classified_as', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
        ]);

        self::assertTrue($result['safe_to_apply']);
        self::assertSame([], $result['blockers']);
    }

    public function test_classified_as_blocks_missing_target_family_with_precise_reason(): void
    {
        $variant = '88888888-8888-4888-8888-888888888888';
        $classification = '99999999-9999-4999-8999-999999999999';
        $this->endpoints->register('variant', new FakeEndpointResolver('variant', [$variant]));
        $this->endpoints->register('classification', new FakeEndpointResolver('classification', [$classification]));
        $service = new RelationshipReadService($this->endpoints, $this->predicates, $this->repository, static fn (): array => ['exists' => true, 'active' => true, 'revision' => 1, 'family' => null]);

        $result = $service->preview([
            'operation' => 'ADD', 'source' => ['type' => 'variant', 'id' => $variant],
            'target' => ['type' => 'classification', 'id' => $classification],
            'predicate' => 'classified_as', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
        ]);

        self::assertFalse($result['safe_to_apply']);
        self::assertContains('CLASSIFICATION_FAMILY_REQUIRED', $result['blockers']);
    }

    public function test_four_tools_are_registered_read_only_and_dispatchable_without_schema_special_case(): void
    {
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        foreach (['nhk.relationship.registry', 'nhk.relationship.list', 'nhk.relationship.get', 'nhk.relationship.preview'] as $tool) {
            self::assertFalse($tools[$tool]['governed']);
            self::assertSame($tool, McpDispatchRegistry::handlerKey($tool));
            self::assertSame('object', $tools[$tool]['inputSchema']['type']);
        }
    }
}
