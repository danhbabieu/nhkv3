<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Graph\{GraphService, StructuralContextQuery};
use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class StructuralContextQueryTest extends TestCase
{
    public function test_variant_brand_context_is_derived_through_two_active_direct_edges(): void
    {
        [$authority, $graph, $brand, $model, $variant] = $this->fixture();
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'variant_of', new NodeReference('model', $model->canonicalId));

        $context = (new StructuralContextQuery($graph, $authority))->forVariant($variant->canonicalId);

        self::assertSame($model->canonicalId, $context->modelId);
        self::assertSame($brand->canonicalId, $context->brandId);
        self::assertSame(['variant_of', 'model_of'], $context->relationPath);
        self::assertSame([], $context->reasons);
    }

    public function test_missing_parent_is_incomplete_and_never_guessed_from_absent_data(): void
    {
        [$authority, $graph, , , $variant] = $this->fixture();

        $context = (new StructuralContextQuery($graph, $authority))->forVariant($variant->canonicalId);

        self::assertNull($context->brandId);
        self::assertContains('STRUCTURAL_PARENT_MISSING', $context->reasons);
        self::assertContains('MISSING_PARENT', $context->reasons);
    }

    public function test_safe_payload_parent_is_explicit_compatibility_evidence_not_canonical_graph_truth(): void
    {
        [$authority, $graph, $brand, $model] = $this->fixture();
        $model = (new AuthorityService($authority, $this->types()))->update($model->canonicalId, ['brand_uuid' => $brand->canonicalId], 1);

        $context = (new StructuralContextQuery($graph, $authority))->forModel($model->canonicalId);

        self::assertSame($brand->canonicalId, $context->brandId);
        self::assertSame('COMPATIBILITY_PAYLOAD', $context->source);
        self::assertFalse($context->canonical);
        self::assertContains('DATA_COMPATIBILITY_GAP', $context->warnings);
    }

    public function test_graph_and_payload_parent_conflict_fails_closed_without_repair(): void
    {
        [$authority, $graph, $brand, $model, $variant, $other] = $this->fixture();
        $model = (new AuthorityService($authority, $this->types()))->update($model->canonicalId, ['brand_uuid' => $brand->canonicalId], 1);
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $other->canonicalId));

        $context = (new StructuralContextQuery($graph, $authority))->forModel($model->canonicalId);

        self::assertContains('RELATIONSHIP_CONFLICT', $context->reasons);
        self::assertNull($context->brandId);
        self::assertCount(1, $graph->findOutgoing(new NodeReference('model', $model->canonicalId), 'model_of')['items']);
    }

    public function test_graph_parent_controls_route_hierarchy_when_payload_is_stale(): void
    {
        [$authority, $graph, $brand, $model, $variant, $other] = $this->fixture();
        $model = (new AuthorityService($authority, $this->types()))->update($model->canonicalId, ['brand_uuid' => $brand->canonicalId], 1);
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $other->canonicalId));
        $contexts = new StructuralContextQuery($graph, $authority);
        $routes = new PublicRouteResolver($authority, $this->types(), $contexts);

        self::assertNull($routes->path($model));
        self::assertContains('RELATIONSHIP_CONFLICT', $contexts->forModel($model->canonicalId)->reasons);
    }

    private function types(): EntityTypeRegistry
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types); return $types;
    }

    private function fixture(): array
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository();
        $service = new AuthorityService($authority, $types);
        $brand = $service->create('brand', 'brand-1', 'Brand');
        $other = $service->create('brand', 'brand-2', 'Brand 2');
        $model = $service->create('model', 'model-1', 'Model');
        $variant = $service->create('variant', 'variant-1', 'Variant');
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand', 'model', 'variant'] as $type) $endpoints->register($type, new FakeEndpointResolver($type, [$brand->canonicalId, $other->canonicalId, $model->canonicalId, $variant->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        return [$authority, $graph, $brand, $model, $variant, $other];
    }
}
