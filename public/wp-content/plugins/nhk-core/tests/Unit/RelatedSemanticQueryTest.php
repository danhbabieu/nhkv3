<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\{GraphService, PredicateTraversalPolicy, RelatedSemanticQuery};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\InMemoryGraphRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class RelatedSemanticQueryTest extends TestCase
{
    public function test_bounded_directional_two_hop_query_dedupes_and_direct_wins(): void
    {
        $ids = array_fill_keys(['brand','model','movement','music'], UuidCodec::newV7());
        $endpoints = new EndpointTypeRegistry();
        foreach ($ids as $type => $id) $endpoints->register($type, new FakeEndpointResolver($type, [$id]));
        $graph = new GraphService($repo = new InMemoryGraphRepository(), $endpoints, $predicates = new PredicateRegistry(), new InMemoryAuditSink());
        $brand = new NodeReference('brand', $ids['brand']); $model = new NodeReference('model', $ids['model']); $movement = new NodeReference('movement', $ids['movement']); $music = new NodeReference('music', $ids['music']);
        $graph->create($brand, 'about', $model); $graph->create($model, 'about', $movement); $graph->create($model, 'about', $music); $graph->create($brand, 'about', $music);
        $result = (new RelatedSemanticQuery($graph, new PredicateTraversalPolicy($predicates)))->query($brand, [], 2, 10);
        self::assertSame('available', $result['status']);
        $musicResult = array_values(array_filter($result['items'], static fn (array $item): bool => $item['target_entity_type'] === 'music'))[0];
        self::assertSame('DIRECT', $musicResult['relationship_class']);
        self::assertSame(1, $musicResult['hop_count']);
        self::assertCount(1, $musicResult['best_path']);
        self::assertNotSame([], $musicResult['alternative_paths']);
        self::assertCount(3, $result['items']);
    }
}
