<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Inventory\GraphInventoryService;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, GraphService, NodeReference, PredicateRegistry};
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class GraphInventoryServiceTest extends TestCase
{
    public function test_filters_before_pagination_and_reports_direction_and_duplicate_diagnostics(): void
    {
        $repository = new InMemoryGraphRepository();
        $source = $repository->resolveNode(new NodeReference('brand', 'b1'));
        $target = $repository->resolveNode(new NodeReference('model', 'm1'));
        $predicates = new PredicateRegistry();
        $repository->createEdge($source, $predicates->get('model_of'), $target);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('brand', new FakeEndpointResolver('brand', ['b1']));
        $endpoints->register('model', new FakeEndpointResolver('model', ['m1']));

        $report = (new GraphInventoryService($repository, $endpoints, $predicates))->inventory(['predicate' => 'model_of'], 1);

        self::assertSame(1, $report->total);
        self::assertCount(1, $report->items);
        self::assertSame('outbound', $report->items[0]['direction']);
        self::assertSame('brand', $report->items[0]['source']['type']);
        self::assertSame('model', $report->items[0]['target']['type']);
        self::assertSame(0, $report->counters['invalid_endpoint']);
        self::assertSame(0, $report->counters['duplicate']);
    }

    public function test_classifies_unknown_and_dangling_endpoints_without_throwing(): void
    {
        $repository = new InMemoryGraphRepository();
        $unknown = $repository->resolveNode(new NodeReference('unknown', 'u1'));
        $dangling = $repository->resolveNode(new NodeReference('brand', 'missing'));
        $repository->createEdge($unknown, (new PredicateRegistry())->get('about'), $dangling);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('brand', new FakeEndpointResolver('brand', ['b1']));

        $report = (new GraphInventoryService($repository, $endpoints, new PredicateRegistry()))->inventory([], 10);

        self::assertSame(1, $report->counters['invalid_endpoint']);
        self::assertSame(1, $report->counters['dangling']);
        self::assertSame(['invalid_endpoint', 'dangling'], $report->items[0]['diagnostics']);
    }
}
