<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\{PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Application\Graph\{BrandAggregationQuery, GraphService};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class PublicEntityCollectionQueryTest extends TestCase
{
    /** @return array{query:PublicEntityCollectionQuery,repository:InMemoryAuthorityRepository} */
    private function query(): array
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $routes = new PublicRouteResolver($repository, $types);
        return ['query' => new PublicEntityCollectionQuery($repository, $types, new PublicIdentityContract($types), new PublicEntityEligibilityPolicy($repository, $types, $routes), $routes), 'repository' => $repository];
    }

    public function test_archive_counts_only_publicly_eligible_routeable_items_and_uses_canonical_urls(): void
    {
        ['query' => $query, 'repository' => $repository] = $this->query();
        $authority = new AuthorityService($repository, $query->types());
        $brand = $authority->create('brand', 'brand-one', 'Brand One');
        $authority->create('brand', 'hidden', 'Video');

        $archive = $query->archive('brand');

        self::assertSame(1, $archive['total']);
        self::assertSame('/brand-one/', $archive['items'][0]['url']);
        self::assertArrayNotHasKey('id', $archive['items'][0]);
        self::assertArrayNotHasKey('stable_key', $archive['items'][0]);
    }

    public function test_detail_and_archive_apply_the_same_identity_and_eligibility_decisions(): void
    {
        ['query' => $query, 'repository' => $repository] = $this->query();
        $authority = new AuthorityService($repository, $query->types());
        $movement = $authority->create('movement', 'cal-100', 'Cal 100');

        self::assertSame('/bo-may/cal-100/', $query->detail('movement', 'cal-100')['url']);
        self::assertSame('/bo-may/cal-100/', $query->archive('movement')['items'][0]['url']);
        self::assertNull($query->detail('movement', 'missing'));
        self::assertArrayNotHasKey('id', $query->detail('movement', 'cal-100'));
        self::assertArrayNotHasKey('stable_key', $query->detail('movement', 'cal-100'));
    }

    public function test_archive_reports_unavailable_infrastructure_instead_of_masquerading_as_empty_data(): void
    {
        ['repository' => $repository] = $this->query();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $routes = new PublicRouteResolver($repository, $types);
        $query = new PublicEntityCollectionQuery(
            $repository,
            $types,
            new PublicIdentityContract($types),
            new PublicEntityEligibilityPolicy($repository, $types, $routes),
            $routes,
            null,
            static fn (): bool => false,
        );

        $archive = $query->archive('brand');

        self::assertFalse($archive['available']);
        self::assertSame([], $archive['items']);
        self::assertSame(0, $archive['total']);
    }

    public function test_brand_detail_can_include_graph_aggregation_without_changing_public_identity(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'brand-one', 'Brand One');
        $model = $authority->create('model', 'model-one', 'Model One', ['brand_uuid' => $brand->canonicalId]);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$brand->canonicalId]));
        $endpoints->register('model', new FakeEndpointResolver('model', [$model->canonicalId]));
        $graph = new GraphService($graphRepository = new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $routes = new PublicRouteResolver($repository, $types);
        $query = new PublicEntityCollectionQuery($repository, $types, new PublicIdentityContract($types), new PublicEntityEligibilityPolicy($repository, $types, $routes), $routes, new BrandAggregationQuery($graph, $repository, $types, $routes));

        $detail = $query->detail('brand', 'brand-one');

        self::assertSame('/brand-one/', $detail['url']);
        self::assertSame('Model One', $detail['aggregation']['models'][0]['name']);
        self::assertSame('/brand-one/model-one/', $detail['aggregation']['models'][0]['url']);
    }
}
