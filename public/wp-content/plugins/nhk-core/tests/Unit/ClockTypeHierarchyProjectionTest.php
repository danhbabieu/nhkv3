<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\EntityProfileResolver;
use NHK\Core\Application\Graph\{ClassifiedAsPolicy, ClockTypeHierarchyProjection, GraphService};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class ClockTypeHierarchyProjectionTest extends TestCase
{
    public function test_projects_active_same_family_parent_and_children_deterministically(): void
    {
        $authorityRepository = new InMemoryAuthorityRepository();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($authorityRepository, $types);
        $parent = $authority->create('classification', 'nhk:classification:clock-type.public', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $child = $authority->create('classification', 'nhk:classification:clock-type.tower', 'Đồng hồ tháp', ['family' => 'clock_type']);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('classification', new FakeEndpointResolver('classification', [$parent->canonicalId, $child->canonicalId]));
        $graphRepository = new InMemoryGraphRepository();
        $graph = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graphRepository->createEdge(
            $graphRepository->resolveNode(new NodeReference('classification', $child->canonicalId)),
            (new PredicateRegistry())->get('subtype_of'),
            $graphRepository->resolveNode(new NodeReference('classification', $parent->canonicalId)),
        );

        $projection = (new ClockTypeHierarchyProjection($authorityRepository, $graph, new EntityProfileResolver()))->project($parent->canonicalId);

        self::assertSame('AVAILABLE', $projection['status']);
        self::assertSame([], $projection['parent']);
        self::assertSame([$child->canonicalId], array_column($projection['children'], 'canonical_id'));
        self::assertSame('clock_type', $projection['children'][0]['family']);
    }

    public function test_omits_legacy_or_other_family_edges_without_granting_clock_type_profile(): void
    {
        $authorityRepository = new InMemoryAuthorityRepository();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($authorityRepository, $types);
        $root = $authority->create('classification', 'nhk:classification:clock-type.public-2', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $caseForm = $authority->create('classification', 'nhk:classification:case-form.shoulder', 'Dáng vai bò', ['family' => 'case_form']);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('classification', new FakeEndpointResolver('classification', [$root->canonicalId, $caseForm->canonicalId]));
        $graphRepository = new InMemoryGraphRepository();
        $graph = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $graphRepository->createEdge(
            $graphRepository->resolveNode(new NodeReference('classification', $caseForm->canonicalId)),
            (new PredicateRegistry())->get('subtype_of'),
            $graphRepository->resolveNode(new NodeReference('classification', $root->canonicalId)),
        );

        $projection = (new ClockTypeHierarchyProjection($authorityRepository, $graph))->project($root->canonicalId);

        self::assertSame('AVAILABLE', $projection['status']);
        self::assertSame([], $projection['children']);
        self::assertContains('INVALID_CLOCK_TYPE_CHILD', $projection['diagnostics']);
    }

    public function test_blocks_when_read_graph_contains_a_hierarchy_cycle(): void
    {
        $authorityRepository = new InMemoryAuthorityRepository();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($authorityRepository, $types);
        $root = $authority->create('classification', 'nhk:classification:clock-type.public-3', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $child = $authority->create('classification', 'nhk:classification:clock-type.tower-3', 'Đồng hồ tháp', ['family' => 'clock_type']);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('classification', new FakeEndpointResolver('classification', [$root->canonicalId, $child->canonicalId]));
        $graphRepository = new InMemoryGraphRepository();
        $predicate = (new PredicateRegistry())->get('subtype_of');
        $graphRepository->createEdge($graphRepository->resolveNode(new NodeReference('classification', $root->canonicalId)), $predicate, $graphRepository->resolveNode(new NodeReference('classification', $child->canonicalId)));
        $graphRepository->createEdge($graphRepository->resolveNode(new NodeReference('classification', $child->canonicalId)), $predicate, $graphRepository->resolveNode(new NodeReference('classification', $root->canonicalId)));
        $graph = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new InMemoryAuditSink());

        $projection = (new ClockTypeHierarchyProjection($authorityRepository, $graph))->project($root->canonicalId);

        self::assertSame('BLOCKED', $projection['status']);
        self::assertContains('CLASSIFICATION_HIERARCHY_CYCLE', $projection['diagnostics']);
    }
}
