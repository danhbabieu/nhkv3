<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\ClassificationHierarchyPolicy;
use NHK\Core\Application\Authority\ClassificationFamilyAudit;
use NHK\Core\Application\Graph\ClassifiedAsPolicy;
use NHK\Core\Application\Graph\ClassificationReadModel;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference};
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\InMemoryGraphRepository;
use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ConversationalAuthorityGraphTest extends TestCase
{
    public function test_subtype_and_classified_as_are_registered_with_closed_endpoints(): void
    {
        $registry = new PredicateRegistry();

        $subtype = $registry->get('subtype_of');
        self::assertSame(['classification'], $subtype->allowed_source_types);
        self::assertSame(['classification'], $subtype->allowed_target_types);
        self::assertSame('ONE', $subtype->outbound_cardinality);
        self::assertSame('MANY', $subtype->inbound_cardinality);

        $classified = $registry->get('classified_as');
        self::assertSame(['model', 'variant', 'specimen', 'product'], $classified->allowed_source_types);
        self::assertSame(['classification'], $classified->allowed_target_types);
        self::assertSame('MANY', $classified->outbound_cardinality);
        self::assertSame('MANY', $classified->inbound_cardinality);
    }

    public function test_mantel_clock_can_be_a_subtype_of_table_clock_but_cycles_are_rejected(): void
    {
        $table = $this->classification('table-clock', 'Table Clock');
        $mantel = $this->classification('mantel-clock', 'Mantel Clock');
        $repository = new GraphAuthorityRepository([$table, $mantel]);
        $graphRepository = new InMemoryGraphRepository();
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('classification', new FakeEndpointResolver('classification', [$table->canonicalId, $mantel->canonicalId]));
        $graph = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), new ClassificationHierarchyPolicy($repository, $graphRepository));

        $edge = $graph->create(new NodeReference('classification', $mantel->canonicalId), 'subtype_of', new NodeReference('classification', $table->canonicalId));
        self::assertSame('subtype_of', $edge->predicate);
        self::assertSame($edge->edge_uuid, $graph->create(new NodeReference('classification', $mantel->canonicalId), 'subtype_of', new NodeReference('classification', $table->canonicalId))->edge_uuid);

        $this->expectExceptionMessage('CLASSIFICATION_HIERARCHY_CYCLE');
        $graph->create(new NodeReference('classification', $table->canonicalId), 'subtype_of', new NodeReference('classification', $mantel->canonicalId));
    }

    public function test_missing_family_blocks_hierarchy_before_graph_mutation(): void
    {
        $table = $this->classification('table-clock', 'Table Clock', []);
        $mantel = $this->classification('mantel-clock', 'Mantel Clock', ['family' => 'clock-type']);
        $repository = new GraphAuthorityRepository([$table, $mantel]);
        $graphRepository = new InMemoryGraphRepository();
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('classification', new FakeEndpointResolver('classification', [$table->canonicalId, $mantel->canonicalId]));
        $graph = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), new ClassificationHierarchyPolicy($repository, $graphRepository));

        $this->expectExceptionMessage('CLASSIFICATION_FAMILY_UNRESOLVED');
        $graph->create(new NodeReference('classification', $mantel->canonicalId), 'subtype_of', new NodeReference('classification', $table->canonicalId));
        self::assertCount(0, $graphRepository->allEdges());
    }

    public function test_family_audit_requires_explicit_mapping_and_is_idempotent(): void
    {
        $table = $this->classification('table-clock', 'Table Clock', []);
        $mantel = $this->classification('mantel-clock', 'Mantel Clock', ['family' => 'clock-type']);
        $audit = new ClassificationFamilyAudit(new GraphAuthorityRepository([$table, $mantel]));

        $first = $audit->planBackfill(['nhk:classification:clock-type.table-clock' => 'clock-type']);
        $second = $audit->planBackfill(['nhk:classification:clock-type.table-clock' => 'clock-type']);

        self::assertSame($first, $second);
        self::assertCount(1, $first['updates']);
        self::assertSame('clock-type', $first['updates'][0]['family']);
        self::assertSame([], $first['unresolved']);
        self::assertSame('CLASSIFICATION_FAMILY_UNRESOLVED', $audit->planBackfill([])['unresolved'][0]['code']);
    }

    public function test_classified_as_keeps_scope_and_blocks_inference_only_or_upward_promotion(): void
    {
        $policy = new ClassifiedAsPolicy();
        $policy->assertCandidate(['source_type' => 'specimen', 'scope' => 'specimen', 'provenance' => 'OBSERVED_FROM_MEDIA']);
        $policy->assertCandidate(['source_type' => 'model', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED']);

        $this->expectExceptionMessage('CLASSIFICATION_SCOPE_UNSUPPORTED');
        $policy->assertCandidate(['source_type' => 'specimen', 'scope' => 'brand', 'provenance' => 'SYSTEM_INFERENCE']);
    }

    public function test_classified_as_rejects_system_inference_without_independent_support(): void
    {
        $this->expectExceptionMessage('CLASSIFICATION_SCOPE_UNSUPPORTED');
        (new ClassifiedAsPolicy())->assertCandidate(['source_type' => 'variant', 'scope' => 'variant', 'provenance' => 'SYSTEM_INFERENCE']);
    }

    public function test_graph_service_enforces_classified_as_scope(): void
    {
        $brand = UuidCodec::newV7(); $classification = UuidCodec::newV7();
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$brand]));
        $endpoints->register('classification', new FakeEndpointResolver('classification', [$classification]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());

        $this->expectExceptionMessage('CLASSIFICATION_SCOPE_UNSUPPORTED');
        $graph->create(new NodeReference('brand', $brand), 'classified_as', new NodeReference('classification', $classification));
    }

    public function test_classified_as_provenance_matrix_does_not_promote_observations_or_listings(): void
    {
        $policy = new ClassifiedAsPolicy();
        $policy->assertCandidate(['source_type' => 'specimen', 'scope' => 'specimen', 'provenance' => 'OBSERVED_FROM_MEDIA']);
        $policy->assertCandidate(['source_type' => 'variant', 'scope' => 'variant', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']);
        $policy->assertCandidate(['source_type' => 'model', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED']);
        $policy->assertCandidate(['source_type' => 'product', 'scope' => 'product', 'provenance' => 'EXTERNAL_RESEARCH']);

        foreach ([['source_type' => 'specimen', 'scope' => 'model', 'provenance' => 'OBSERVED_FROM_MEDIA'], ['source_type' => 'product', 'scope' => 'model', 'provenance' => 'EXTERNAL_RESEARCH'], ['source_type' => 'model', 'scope' => 'model', 'provenance' => 'SYSTEM_INFERENCE']] as $packet) {
            try { $policy->assertCandidate($packet); self::fail('Expected scope/provenance to be blocked.'); } catch (\RuntimeException $error) { self::assertSame('CLASSIFICATION_SCOPE_UNSUPPORTED', $error->getMessage()); }
        }
    }

    public function test_classification_read_model_separates_child_types_from_facet_filters(): void
    {
        $table = $this->classification('table-clock', 'Đồng hồ để bàn');
        $mantel = $this->classification('mantel-clock', 'Mantel Clock');
        $france = new AuthorityEntity(UuidCodec::newV7(), 'classification', 'nhk:classification:origin.france', 'Pháp', 1, ['family' => 'origin'], AuthorityState::ACTIVE, 1);
        $authority = new GraphAuthorityRepository([$table, $mantel, $france]); $graphRepository = new InMemoryGraphRepository();
        $endpoints = new EndpointTypeRegistry(); $endpoints->register('classification', new FakeEndpointResolver('classification', [$table->canonicalId, $mantel->canonicalId, $france->canonicalId]));
        $graph = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), new ClassificationHierarchyPolicy($authority, $graphRepository));
        $graph->create(new NodeReference('classification', $mantel->canonicalId), 'subtype_of', new NodeReference('classification', $table->canonicalId));

        $projection = (new ClassificationReadModel($authority, $graph))->project($table->canonicalId);
        self::assertSame(['Mantel Clock'], array_column($projection['children'], 'name'));
        self::assertSame(['Pháp'], array_column($projection['facet_filters'], 'name'));
        self::assertSame('CHILD_TYPE', $projection['children'][0]['kind']);
        self::assertSame('FACET_FILTER', $projection['facet_filters'][0]['kind']);
    }

    private function classification(string $key, string $name, array $payload = ['family' => 'clock-type']): AuthorityEntity
    {
        return new AuthorityEntity(UuidCodec::newV7(), 'classification', 'nhk:classification:clock-type.' . $key, $name, 1, $payload, AuthorityState::ACTIVE, 1);
    }
}

final class GraphAuthorityRepository implements AuthorityRepository
{
    /** @param list<AuthorityEntity> $items */
    public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->items as $item) if ($item->entityType === $type && $item->stableKey === $key) return $item; return null; }
    public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
    public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
    public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
    public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (AuthorityEntity $item): bool => $item->entityType === $type && ($includeRetired || $item->active()))); }
}
