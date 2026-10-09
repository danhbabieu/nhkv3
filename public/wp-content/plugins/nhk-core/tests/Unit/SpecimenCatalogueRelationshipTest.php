<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Authority\{AuthorityService, ProductSpecimenListingPolicy};
use NHK\Core\Application\Catalogue\SpecimenCatalogueQuery;
use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Authority\CanonicalEntityTypeCatalog;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Graph\Exception\{InvalidRelationTargetType, RelationCardinalityViolation};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class SpecimenCatalogueRelationshipTest extends TestCase
{
    public function test_specimen_identity_is_one_active_model_or_variant_and_never_a_direct_brand_edge(): void
    {
        $model = '550e8400-e29b-41d4-a716-446655440001';
        $variant = '550e8400-e29b-41d4-a716-446655440002';
        $specimen = '550e8400-e29b-41d4-a716-446655440003';
        $graph = $this->graph([$model, $variant, $specimen]);

        $graph->create(new NodeReference('specimen', $specimen), 'specimen_of', new NodeReference('model', $model));
        self::assertSame(1, count($graph->findOutgoing(new NodeReference('specimen', $specimen), 'specimen_of')['items']));

        $this->expectException(RelationCardinalityViolation::class);
        $graph->create(new NodeReference('specimen', $specimen), 'specimen_of', new NodeReference('variant', $variant));
    }

    public function test_specimen_identity_rejects_brand_and_product_can_list_only_one_specimen(): void
    {
        $specimen = '550e8400-e29b-41d4-a716-446655440003';
        $brand = '550e8400-e29b-41d4-a716-446655440004';
        $product = '550e8400-e29b-41d4-a716-446655440005';
        $other = '550e8400-e29b-41d4-a716-446655440006';
        $graph = $this->graph([$specimen, $brand, $product, $other]);

        try {
            $graph->create(new NodeReference('specimen', $specimen), 'specimen_of', new NodeReference('brand', $brand));
            self::fail('A Specimen must not point directly to a Brand.');
        } catch (InvalidRelationTargetType) {
            self::assertTrue(true);
        }

        $graph->create(new NodeReference('product', $product), 'lists_specimen', new NodeReference('specimen', $specimen));
        $this->expectException(RelationCardinalityViolation::class);
        $graph->create(new NodeReference('product', $product), 'lists_specimen', new NodeReference('specimen', $other));
    }

    public function test_many_specimens_can_share_one_variant_without_cloning_the_variant(): void
    {
        $variant = UuidCodec::newV7();
        $specimens = array_map(static fn (): string => UuidCodec::newV7(), range(1, 100));
        $graph = $this->graph(array_merge([$variant], $specimens));

        foreach ($specimens as $specimen) {
            $graph->create(new NodeReference('specimen', $specimen), 'specimen_of', new NodeReference('variant', $variant));
        }

        self::assertCount(100, $graph->findIncoming(new NodeReference('variant', $variant), 'specimen_of', 0, 200)['items']);
    }

    public function test_catalogue_keeps_specimen_and_product_routes_and_projects_only_public_identity(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'brand-a', 'Brand A');
        $model = $authority->create('model', 'model-a', 'Model A', ['brand_uuid' => $brand->canonicalId]);
        $variant = $authority->create('variant', 'variant-a', 'Variant A', ['model_uuid' => $model->canonicalId]);
        $specimen = $authority->create('specimen', 'object-a', 'Object A', ['serial_number' => 'SN-A']);
        $product = $authority->create('product', 'listing-a', 'Listing A', ['offer_state' => 'listed', 'price' => 100]);
        $graph = $this->graph(array_map(static fn ($entity): string => $entity->canonicalId, [$brand, $model, $variant, $specimen, $product]));
        $graph->create(new NodeReference('model', $model->canonicalId), 'model_of', new NodeReference('brand', $brand->canonicalId));
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'variant_of', new NodeReference('model', $model->canonicalId));
        $graph->create(new NodeReference('specimen', $specimen->canonicalId), 'specimen_of', new NodeReference('variant', $variant->canonicalId));
        $graph->create(new NodeReference('product', $product->canonicalId), 'lists_specimen', new NodeReference('specimen', $specimen->canonicalId));

        $query = new SpecimenCatalogueQuery($repository, $types, $graph, new PublicRouteResolver($repository, $types), media: static fn (): array => ['representative' => ['url' => 'https://cdn.example/object-a.jpg', 'alt' => 'Object A']]);
        $specimenItem = $query->detail('specimen', 'object-a');
        $productItem = $query->detail('product', 'listing-a');

        self::assertSame('/hien-vat/object-a/', $specimenItem['url']);
        self::assertSame('/san-pham/listing-a/', $productItem['url']);
        self::assertSame('Variant A', $specimenItem['identity']['variant']['name']);
        self::assertSame('Brand A', $specimenItem['identity']['brand']['name']);
        self::assertSame('/hien-vat/object-a/', $productItem['specimen']['url']);
        self::assertSame('https://cdn.example/object-a.jpg', $specimenItem['media']['representative']['url']);
        self::assertStringNotContainsString($specimen->canonicalId, (string) json_encode([$specimenItem, $productItem]));
        self::assertStringNotContainsString($product->canonicalId, (string) json_encode([$specimenItem, $productItem]));
    }

    public function test_active_listing_conflict_is_read_only_and_historical_offer_is_allowed(): void
    {
        $policy = new ProductSpecimenListingPolicy();

        self::assertSame('blocked', $policy->assess([['active' => true, 'offer_state' => 'listed']], 'listed')['status']);
        self::assertSame('allowed', $policy->assess([['active' => true, 'offer_state' => 'listed']], 'sold')['status']);
        self::assertSame('allowed', $policy->assess([['active' => false, 'offer_state' => 'listed']], 'listed')['status']);
    }

    /** @param list<string> $keys */
    private function graph(array $keys): GraphService
    {
        $types = new EndpointTypeRegistry();
        foreach (['brand', 'model', 'variant', 'specimen', 'product'] as $type) {
            $types->register($type, new FakeEndpointResolver($type, $keys));
        }
        return new GraphService(new InMemoryGraphRepository(), $types, new PredicateRegistry(), new InMemoryAuditSink());
    }
}
