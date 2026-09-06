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
    public function test_brand_aggregation_includes_directly_linked_authority_records_across_registered_types(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository(); $service = new AuthorityService($authority, $types);
        $brand = $service->create('brand', 'maker', 'Maker');
        $movement = $service->create('movement', 'maker-30', 'Máy 30');
        $music = $service->create('music', 'music-a', 'Music A');
        $component = $service->create('component', 'hand-54', 'Kim 54');
        $classification = $service->create('classification', 'gong-family', 'Côn');
        $unrelated = $service->create('movement', 'other-30', 'Máy 35');
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand', 'movement', 'music', 'component', 'classification'] as $type) {
            $ids = array_values(array_map(static fn ($entity): string => $entity->canonicalId, array_filter($authority->listByType($type), static fn ($entity): bool => $entity->entityType === $type)));
            $endpoints->register($type, new FakeEndpointResolver($type, $ids));
        }
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        foreach ([$movement, $music, $component, $classification] as $entity) {
            $graph->create(new NodeReference('brand', $brand->canonicalId), 'about', new NodeReference($entity->entityType, $entity->canonicalId));
        }

        $result = (new BrandAggregationQuery($graph, $authority, $types))->forBrand($brand->canonicalId);

        self::assertSame(['Máy 30'], array_column($result['movements'], 'name'));
        self::assertSame(['Music A'], array_column($result['music'], 'name'));
        self::assertSame(['Kim 54'], array_column($result['components'], 'name'));
        self::assertSame(['Côn'], array_column($result['classifications'], 'name'));
        self::assertSame([], array_filter($result['movements'], static fn (array $item): bool => $item['name'] === $unrelated->canonicalName));
        self::assertSame('DIRECT', $result['movements'][0]['origin']['kind']);
        self::assertSame(['about'], $result['movements'][0]['origin']['path']);
    }

    public function test_direct_brand_link_wins_over_a_derived_variant_path_without_duplicate_output(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository(); $service = new AuthorityService($authority, $types);
        $brand = $service->create('brand', 'maker', 'Maker');
        $model = $service->create('model', 'model-39', 'Model 39');
        $variant = $service->create('variant', 'model-39-variant', 'Model 39 Variant');
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand', 'model', 'variant'] as $type) $endpoints->register($type, new FakeEndpointResolver($type, [$brand->canonicalId, $model->canonicalId, $variant->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'variant_of', new NodeReference('model', $model->canonicalId));
        $graph->create(new NodeReference('brand', $brand->canonicalId), 'about', new NodeReference('variant', $variant->canonicalId));

        $variants = (new BrandAggregationQuery($graph, $authority, $types))->forBrand($brand->canonicalId)['variants'];

        self::assertCount(1, $variants);
        self::assertSame('DIRECT', $variants[0]['origin']['kind']);
        self::assertSame(['about'], $variants[0]['origin']['path']);
    }

    public function test_brand_recipe_surfaces_variant_movement_path_with_exact_origin(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository(); $service = new AuthorityService($authority, $types);
        $brand = $service->create('brand', 'maker', 'Maker');
        $model = $service->create('model', 'model-39', 'Model 39');
        $variant = $service->create('variant', 'model-39-variant', 'Model 39 Variant');
        $movement = $service->create('movement', 'movement-30', 'Machine 30');
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand' => $brand, 'model' => $model, 'variant' => $variant, 'movement' => $movement] as $type => $entity) $endpoints->register($type, new FakeEndpointResolver($type, [$entity->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'variant_of', new NodeReference('model', $model->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'uses_movement', new NodeReference('movement', $movement->canonicalId));

        $result = (new BrandAggregationQuery($graph, $authority, $types))->forBrand($brand->canonicalId);

        self::assertCount(1, $result['movements']);
        self::assertSame('Machine 30', $result['movements'][0]['name']);
        self::assertSame('DERIVED', $result['movements'][0]['origin']['kind']);
        self::assertSame(3, $result['movements'][0]['origin']['hop_count']);
        self::assertSame(['model_of', 'variant_of', 'uses_movement'], $result['movements'][0]['origin']['path']);
    }

    public function test_brand_recipe_deduplicates_music_reached_by_variant_and_movement_paths(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository(); $service = new AuthorityService($authority, $types);
        $brand = $service->create('brand', 'maker', 'Maker');
        $model = $service->create('model', 'model-39', 'Model 39');
        $variant = $service->create('variant', 'model-39-variant', 'Model 39 Variant');
        $movement = $service->create('movement', 'movement-30', 'Machine 30');
        $music = $service->create('music', 'music-a', 'Music A');
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand' => $brand, 'model' => $model, 'variant' => $variant, 'movement' => $movement, 'music' => $music] as $type => $entity) $endpoints->register($type, new FakeEndpointResolver($type, [$entity->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'variant_of', new NodeReference('model', $model->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'uses_movement', new NodeReference('movement', $movement->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'configured_with_music', new NodeReference('music', $music->canonicalId));
        $graph->create(new NodeReference('movement', $movement->canonicalId), 'supports_music', new NodeReference('music', $music->canonicalId));

        $result = (new BrandAggregationQuery($graph, $authority, $types))->forBrand($brand->canonicalId);

        self::assertCount(1, $result['music']);
        self::assertSame('Music A', $result['music'][0]['name']);
        self::assertSame(['model_of', 'variant_of', 'configured_with_music'], $result['music'][0]['origin']['path']);
        self::assertSame(3, $result['music'][0]['origin']['hop_count']);
    }

    public function test_brand_aggregation_returns_models_and_variants_with_derived_paths(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository(); $service = new AuthorityService($authority, $types);
        $brand = $service->create('brand', 'brand-1', 'Brand');
        $model = $service->create('model', 'model-1', 'Model');
        $variant = $service->create('variant', 'variant-1', 'Variant');
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand', 'model', 'variant'] as $type) $endpoints->register($type, new FakeEndpointResolver($type, [$brand->canonicalId, $model->canonicalId, $variant->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'variant_of', new NodeReference('model', $model->canonicalId));

        $result = (new BrandAggregationQuery($graph, $authority, $types))->forBrand($brand->canonicalId);

        self::assertArrayNotHasKey('id', $result['models'][0]);
        self::assertSame('DIRECT', $result['models'][0]['origin']['kind']);
        self::assertArrayNotHasKey('id', $result['variants'][0]);
        self::assertSame(['model_of', 'variant_of'], $result['variants'][0]['origin']['path']);
        self::assertSame([], $result['movements']);
    }
}
