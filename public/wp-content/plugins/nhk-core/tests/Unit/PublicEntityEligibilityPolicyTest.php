<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\{PublicEntityEligibilityPolicy, PublicRouteResolver};
use NHK\Core\Application\Graph\{GraphService, StructuralContextQuery};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class PublicEntityEligibilityPolicyTest extends TestCase
{
    public function test_policy_blocks_an_inactive_entity(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($repository, $types);
        $entity = $authority->create('brand', 'retired', 'Retired');
        $authority->retire($entity->canonicalId, 1);

        $result = (new PublicEntityEligibilityPolicy($repository, $types, new PublicRouteResolver($repository, $types)))->evaluate($repository->findByCanonicalId($entity->canonicalId));

        self::assertFalse($result->eligible);
        self::assertContains('INACTIVE', $result->reasons);
    }

    public function test_policy_blocks_a_reserved_brand_slug_without_treating_it_as_public_content(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $entity = (new AuthorityService($repository, $types))->create('brand', 'reserved', 'Video');

        $result = (new PublicEntityEligibilityPolicy($repository, $types, new PublicRouteResolver($repository, $types)))->evaluate($entity);

        self::assertFalse($result->eligible);
        self::assertContains('UNAVAILABLE', $result->reasons);
    }

    public function test_policy_drops_the_transitional_warning_when_the_canonical_graph_parent_exists(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'brand-one', 'Brand One');
        $model = $authority->create('model', 'model-one', 'Model One', ['brand_uuid' => $brand->canonicalId]);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$brand->canonicalId]));
        $endpoints->register('model', new FakeEndpointResolver('model', [$model->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $policy = new PublicEntityEligibilityPolicy($repository, $types, new PublicRouteResolver($repository, $types), new StructuralContextQuery($graph, $repository));

        $result = $policy->evaluate($model);

        self::assertTrue($result->eligible);
        self::assertNotContains('DATA_COMPATIBILITY_GAP', $result->warnings);
    }
}
