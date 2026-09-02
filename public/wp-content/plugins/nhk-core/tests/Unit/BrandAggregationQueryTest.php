<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Graph\{BrandAggregationQuery, GraphService};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class BrandAggregationQueryTest extends TestCase
{
    public function test_brand_aggregation_returns_models_and_variants_with_derived_paths(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository(); $service = new AuthorityService($authority, $types);
        $brand = $service->create('brand', 'brand-1', 'Brand');
        $model = $service->create('model', 'model-1', 'Model');
        $variant = $service->create('variant', 'variant-1', 'Variant');
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand', 'model', 'variant'] as $type) $endpoints->register($type, new FakeEndpointResolver($type, [$brand->canonicalId, $model->canonicalId, $variant->canonicalId]));
        $graph = new GraphService($repository = new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'variant_of', new NodeReference('model', $model->canonicalId));

        $result = (new BrandAggregationQuery($graph, $authority, $types))->forBrand($brand->canonicalId);

        self::assertArrayNotHasKey('id', $result['models'][0]);
        self::assertSame('DIRECT', $result['models'][0]['origin']['kind']);
        self::assertArrayNotHasKey('id', $result['variants'][0]);
        self::assertSame(['variant_of', 'model_of'], $result['variants'][0]['origin']['path']);
        self::assertSame([], $result['movements']);
    }
}
