<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Graph\{BrandAggregationQuery, ClassifiedAsPolicy, ClockTypeDerivedRelationshipQuery, GraphService};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class ClockTypeDerivedRelationshipQueryTest extends TestCase
{
    public function test_canonical_clock_type_is_derived_for_brand_without_shortcut_edge(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepository = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepository, $types);
        $brand = $authority->create('brand', 'nhk:brand:odo-derived', 'Odo');
        $model = $authority->create('model', 'nhk:model:public-clock', 'Public Clock Model');
        $clockType = $authority->create('classification', 'nhk:classification:clock-type.public-clock', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand' => $brand, 'model' => $model, 'classification' => $clockType] as $type => $entity) {
            $endpoints->register($type, new FakeEndpointResolver($type, [$entity->canonicalId]));
        }
        $graphRepository = new InMemoryGraphRepository();
        $graph = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('model', $model->canonicalId), 'classified_as', new NodeReference('classification', $clockType->canonicalId));

        $result = (new ClockTypeDerivedRelationshipQuery($graph, $authorityRepository))->forBrand($brand->canonicalId);

        self::assertSame('AVAILABLE_WITH_ITEMS', $result['status']);
        self::assertSame($clockType->canonicalId, $result['items'][0]['canonical_id']);
        self::assertSame('DERIVED', $result['items'][0]['relationship_class']);
        self::assertSame(['model_of', 'classified_as'], array_column($result['items'][0]['best_path'], 'predicate'));
        self::assertNull($graph->findEdge(new NodeReference('brand', $brand->canonicalId), 'classified_as', new NodeReference('classification', $clockType->canonicalId)));
    }

    public function test_brand_aggregation_uses_canonical_family_and_excludes_legacy_family(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepository = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepository, $types);
        $brand = $authority->create('brand', 'nhk:brand:aggregation', 'Brand');
        $model = $authority->create('model', 'nhk:model:aggregation', 'Model');
        $canonical = $authority->create('classification', 'nhk:classification:clock-type.canonical', 'Canonical Type', ['family' => 'clock_type']);
        $legacy = $authority->create('classification', 'nhk:classification:clock-type.legacy', 'Legacy Type', ['family' => 'clock-type']);
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand' => $brand, 'model' => $model] as $type => $entity) $endpoints->register($type, new FakeEndpointResolver($type, [$entity->canonicalId]));
        $endpoints->register('classification', new FakeEndpointResolver('classification', [$canonical->canonicalId, $legacy->canonicalId]));
        $graphRepository = new InMemoryGraphRepository();
        $graph = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('model', $model->canonicalId), 'classified_as', new NodeReference('classification', $canonical->canonicalId));
        $graph->create(new NodeReference('model', $model->canonicalId), 'classified_as', new NodeReference('classification', $legacy->canonicalId));

        $result = (new BrandAggregationQuery($graph, $authorityRepository, $types))->forBrand($brand->canonicalId);

        self::assertSame(['Canonical Type'], array_column($result['clock_types'], 'name'));
    }

    public function test_derived_clock_type_query_excludes_legacy_family_compatibility_reads(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepository = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepository, $types);
        $brand = $authority->create('brand', 'nhk:brand:legacy-filter', 'Brand');
        $model = $authority->create('model', 'nhk:model:legacy-filter', 'Model');
        $legacy = $authority->create('classification', 'nhk:classification:clock-type.legacy-filter', 'Legacy Type', ['family' => 'clock-type']);
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand' => $brand, 'model' => $model] as $type => $entity) $endpoints->register($type, new FakeEndpointResolver($type, [$entity->canonicalId]));
        $endpoints->register('classification', new FakeEndpointResolver('classification', [$legacy->canonicalId]));
        $graphRepository = new InMemoryGraphRepository();
        $graph = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('model', $model->canonicalId), 'classified_as', new NodeReference('classification', $legacy->canonicalId));

        $result = (new ClockTypeDerivedRelationshipQuery($graph, $authorityRepository))->forBrand($brand->canonicalId);

        self::assertSame('AVAILABLE_EMPTY', $result['status']);
        self::assertSame([], $result['items']);
    }
}
