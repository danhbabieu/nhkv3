<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\ClockTypeDossierProjection;
use NHK\Core\Application\Graph\{ClassifiedAsPolicy, ClockTypeDerivedRelationshipQuery, ClockTypeHierarchyProjection, GraphService};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class ClockTypeDossierProjectionTest extends TestCase
{
    public function test_adds_clock_type_hierarchy_derived_brands_and_media_origin_without_writing(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepository = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepository, $types);
        $clockType = $authority->create('classification', 'nhk:classification:clock-type.public-dossier', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $child = $authority->create('classification', 'nhk:classification:clock-type.tower-dossier', 'Đồng hồ tháp', ['family' => 'clock_type']);
        $brand = $authority->create('brand', 'nhk:brand:dossier', 'Odo');
        $model = $authority->create('model', 'nhk:model:dossier', 'Model công cộng');
        $endpoints = new EndpointTypeRegistry();
        foreach (['classification' => [$clockType->canonicalId, $child->canonicalId], 'brand' => [$brand->canonicalId], 'model' => [$model->canonicalId]] as $type => $ids) {
            $endpoints->register($type, new FakeEndpointResolver($type, $ids));
        }
        $graphRepository = new InMemoryGraphRepository();
        $predicateRegistry = new PredicateRegistry();
        $graph = new GraphService($graphRepository, $endpoints, $predicateRegistry, new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());
        $subtype = $predicateRegistry->get('subtype_of');
        $graphRepository->createEdge($graphRepository->resolveNode(new NodeReference('classification', $child->canonicalId)), $subtype, $graphRepository->resolveNode(new NodeReference('classification', $clockType->canonicalId)));
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('model', $model->canonicalId), 'classified_as', new NodeReference('classification', $clockType->canonicalId));

        $base = [
            'status' => 'AVAILABLE',
            'relation_sections' => [
                'models' => [['type' => 'model', 'title' => 'Model công cộng', 'origin' => ['kind' => 'DIRECT', 'hop_count' => 1]]],
                'media' => [['type' => 'media', 'title' => 'Ảnh hiện vật', 'origin' => ['kind' => 'DERIVED', 'hop_count' => 2]]],
                'videos' => [],
                'articles' => [],
            ],
            'media_gallery' => [['url' => '/anh/dong-ho-cong-cong.webp', 'role' => 'representative']],
            'knowledge' => ['status' => 'AVAILABLE', 'facets' => [], 'claim_count' => 0],
            'profile' => [],
        ];
        $projection = new ClockTypeDossierProjection(
            new ClockTypeHierarchyProjection($authorityRepository, $graph),
            new ClockTypeDerivedRelationshipQuery($graph, $authorityRepository),
        );

        $result = $projection->forEntity($clockType, $base);

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame('AVAILABLE', $result['clock_type_hierarchy']['status']);
        self::assertSame([$child->canonicalId], array_column($result['clock_type_hierarchy']['children'], 'canonical_id'));
        self::assertSame(['Đồng hồ tháp'], array_column($result['profile']['hierarchy']['children'], 'name'));
        self::assertSame([$brand->canonicalId], array_column($result['relation_sections']['brands'], 'canonical_id'));
        self::assertSame('DERIVED', $result['relation_sections']['brands'][0]['origin']['kind']);
        self::assertSame('AVAILABLE_WITH_ITEMS', $result['clock_type_derived_brands']['status']);
        self::assertSame('AVAILABLE_WITH_ITEMS', $result['media_context']['direct']['status']);
        self::assertSame('AVAILABLE_WITH_ITEMS', $result['media_context']['derived']['status']);
        self::assertArrayNotHasKey('writes', $result);
    }
}
