<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\{GraphService, PredicateTraversalPolicy, RelatedSemanticQuery, SemanticNeighborhoodQuery};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class SemanticNeighborhoodQueryTest extends TestCase
{
    public function test_named_profile_returns_direct_and_derived_deduplicated_targets_within_bound(): void
    {
        $registry = new EndpointTypeRegistry();
        foreach (['classification', 'model', 'brand'] as $type) $registry->register($type, new FakeEndpointResolver($type, ['root', 'model', 'brand']));
        $graph = new GraphService(new InMemoryGraphRepository(), $registry, new PredicateRegistry(), new InMemoryAuditSink());
        $classification = new NodeReference('classification', 'root');
        $graph->create($classification, 'about', new NodeReference('model', 'model'));
        $graph->create(new NodeReference('model', 'model'), 'model_of', new NodeReference('brand', 'brand'));

        $result = (new SemanticNeighborhoodQuery(new RelatedSemanticQuery($graph, new PredicateTraversalPolicy(new PredicateRegistry()))))->query($classification, 'classification');

        self::assertSame('available', $result['status']);
        self::assertSame(['model', 'brand'], array_map(static fn (array $item): string => $item['target_entity_type'], $result['items']));
        self::assertSame(['DIRECT', 'DERIVED'], array_map(static fn (array $item): string => $item['relationship_class'], $result['items']));
    }

    public function test_unknown_profile_and_unbounded_depth_fail_closed(): void
    {
        $registry = new EndpointTypeRegistry();
        $registry->register('classification', new FakeEndpointResolver('classification', ['root']));
        $graph = new GraphService(new InMemoryGraphRepository(), $registry, new PredicateRegistry(), new InMemoryAuditSink());
        $query = new SemanticNeighborhoodQuery(new RelatedSemanticQuery($graph, new PredicateTraversalPolicy(new PredicateRegistry())));

        self::assertSame('unsupported', $query->query(new NodeReference('classification', 'root'), 'unknown')['status']);
        self::assertSame('unsupported', $query->query(new NodeReference('classification', 'root'), 'classification', 3)['status']);
    }

    public function test_reverse_traversal_preserves_persisted_direction_and_never_rewrites_predicate_as_forward_fact(): void
    {
        $registry = new EndpointTypeRegistry();
        foreach (['model', 'variant'] as $type) {
            $registry->register($type, new FakeEndpointResolver($type, ['model-1', 'variant-1']));
        }
        $graph = new GraphService(new InMemoryGraphRepository(), $registry, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('variant', 'variant-1'), 'variant_of', new NodeReference('model', 'model-1'));

        $result = (new SemanticNeighborhoodQuery(new RelatedSemanticQuery($graph, new PredicateTraversalPolicy(new PredicateRegistry()))))
            ->query(new NodeReference('model', 'model-1'), 'model');

        self::assertSame('available', $result['status']);
        $path = $result['items'][0]['best_path'];
        self::assertSame('variant:variant-1', $path[0]['persisted_source']);
        self::assertSame('model:model-1', $path[0]['persisted_target']);
        self::assertSame('INCOMING', $path[0]['traversal_direction']);
        self::assertSame('INVERSE_TRAVERSAL_OF_PERSISTED_EDGE', $path[0]['directional_semantics']);
        self::assertSame('variant_of', $path[0]['predicate']);
        self::assertNotSame('model:model-1', $path[0]['persisted_source']);
    }
}
