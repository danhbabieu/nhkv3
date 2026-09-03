<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\{InMemoryAuditSink, SemanticMergeGraphAdapter};
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class SemanticMergeGraphAdapterTest extends TestCase
{
    public function test_moves_inbound_and_outbound_edges_and_verifies_both_directions(): void
    {
        [$adapter, $graph, $source, $target] = $this->fixture();
        $graph->create(new NodeReference('component', 'odo.source'), 'about', new NodeReference('brand', 'odo'));
        $graph->create(new NodeReference('wp_post', '1:38'), 'about', new NodeReference('component', 'odo.source'));

        $planned = $adapter->plan($source, $target, $adapter->enumerate($source, $target));
        self::assertCount(2, $planned);
        foreach ($planned as $item) {
            $result = $adapter->apply($item);
            self::assertSame('moved', $result['action']);
            self::assertTrue($adapter->verify($item));
        }

        self::assertCount(0, $adapter->enumerate(
            new AuthorityEntity($source->canonicalId, $source->entityType, $source->stableKey, $source->canonicalName, 1, [], \NHK\Core\Domain\Authority\AuthorityState::RETIRED),
            $target,
        ));
    }

    public function test_deduplicates_an_existing_target_triple_and_retires_only_the_source_edge(): void
    {
        [$adapter, $graph, $source, $target] = $this->fixture();
        $graph->create(new NodeReference('component', 'odo.source'), 'about', new NodeReference('brand', 'odo'));
        $graph->create(new NodeReference('component', 'odo.target'), 'about', new NodeReference('brand', 'odo'));

        $planned = $adapter->plan($source, $target, $adapter->enumerate($source, $target));
        self::assertCount(1, $planned);
        self::assertSame('deduped', $adapter->apply($planned[0])['action']);
        self::assertTrue($adapter->verify($planned[0]));
    }

    /** @return array{SemanticMergeGraphAdapter,GraphService,AuthorityEntity,AuthorityEntity} */
    private function fixture(): array
    {
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('component', new FakeEndpointResolver('component', ['odo.source', 'odo.target']));
        $endpoints->register('brand', new FakeEndpointResolver('brand', ['odo']));
        $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:38']));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $source = new AuthorityEntity('018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f11', 'component', 'odo.source', 'Source', 1, []);
        $target = new AuthorityEntity('018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f12', 'component', 'odo.target', 'Target', 1, []);
        return [new SemanticMergeGraphAdapter($graph), $graph, $source, $target];
    }
}
