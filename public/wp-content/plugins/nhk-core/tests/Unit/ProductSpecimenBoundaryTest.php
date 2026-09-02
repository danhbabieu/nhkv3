<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Authority\ProductSpecimenAssessment;
use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Authority\Exception\InvalidPayload;
use NHK\Core\Domain\Authority\CanonicalEntityTypeCatalog;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class ProductSpecimenBoundaryTest extends TestCase
{
    public function test_registry_assigns_physical_and_commercial_fields_to_the_correct_identity(): void
    {
        $registry = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($registry);

        self::assertSame(
            ['model_uuid', 'serial_number', 'acquired_at', 'notes', 'physical_provenance', 'technical_observations', 'condition_observations'],
            $registry->get('specimen')->allowedFields,
        );
        self::assertSame(
            ['vendor', 'url', 'price', 'currency', 'availability', 'listing_title', 'listing_copy', 'offer_state', 'inventory_state', 'listing_start_at', 'listing_end_at', 'commercial_lifecycle', 'condition_copy'],
            $registry->get('product')->allowedFields,
        );
        self::assertNotContains('specimen_uuid', $registry->get('product')->allowedFields);
    }

    public function test_product_cannot_store_physical_identity_or_specimen_observations(): void
    {
        $authority = $this->authority();
        $this->expectException(InvalidPayload::class);

        $authority->create('product', 'listing-physical-fields', 'Listing', [
            'serial_number' => 'SN-1',
            'technical_observations' => ['running' => true],
        ]);
    }

    public function test_product_cannot_use_unapproved_specimen_link_or_physical_identification_fields(): void
    {
        $authority = $this->authority();
        $this->expectException(InvalidPayload::class);

        $authority->create('product', 'listing-unapproved-link', 'Listing', [
            'specimen_uuid' => '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22',
        ]);
    }

    public function test_specimen_cannot_store_commercial_listing_truth(): void
    {
        $authority = $this->authority();
        $this->expectException(InvalidPayload::class);

        $authority->create('specimen', 'object-commerce-fields', 'Object', [
            'serial_number' => 'SN-2',
            'price' => 100.0,
            'availability' => 'listed',
        ]);
    }

    public function test_specific_product_without_specimen_is_blocked_but_generic_product_is_allowed(): void
    {
        $authority = $this->authority();
        $product = $authority->create('product', 'listing-specific', 'Specific listing', [
            'listing_title' => 'Specific listing',
            'offer_state' => 'listed',
        ]);
        $assessment = new ProductSpecimenAssessment();

        self::assertSame('PRODUCT_REQUIRES_SPECIMEN', $assessment->assess($product, true)->reasonCode);
        self::assertFalse($assessment->assess($product, true)->semanticallyComplete);
        self::assertSame('PRODUCT_WITHOUT_SPECIMEN_ALLOWED', $assessment->assess($product, false)->reasonCode);
        self::assertTrue($assessment->assess($product, false)->semanticallyComplete);
    }

    public function test_product_can_be_assessed_with_one_specimen_without_persisting_a_second_identity(): void
    {
        $authority = $this->authority();
        $specimen = $authority->create('specimen', 'object-one', 'Object one', [
            'serial_number' => 'SN-3',
            'condition_observations' => ['case' => 'good'],
        ]);
        $product = $authority->create('product', 'listing-one', 'Listing one', ['price' => 100.0]);
        $assessment = new ProductSpecimenAssessment();

        $result = $assessment->assess($product, true, [$specimen]);

        self::assertSame('PRODUCT_WITH_SPECIMEN', $result->reasonCode);
        self::assertTrue($result->semanticallyComplete);
        self::assertSame($specimen->canonicalId, $result->specimenId);
        $authority->create('product', 'listing-two', 'Listing two', ['price' => 200.0]);
        self::assertCount(2, $authority->list('product'));
        self::assertCount(1, $authority->list('specimen'));
    }

    public function test_product_cannot_reference_two_specimens_and_conflict_does_not_guess(): void
    {
        $authority = $this->authority();
        $product = $authority->create('product', 'listing-conflict', 'Conflicting listing');
        $first = $authority->create('specimen', 'object-first', 'Object first', ['serial_number' => 'SN-4']);
        $second = $authority->create('specimen', 'object-second', 'Object second', ['serial_number' => 'SN-5']);

        $result = (new ProductSpecimenAssessment())->assess($product, true, [$first, $second]);

        self::assertSame('PRODUCT_SPECIMEN_CONFLICT', $result->reasonCode);
        self::assertFalse($result->semanticallyComplete);
        self::assertNull($result->specimenId);
    }

    public function test_product_lifecycle_and_commerce_edits_do_not_delete_or_mutate_specimen(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($repository, $this->types());
        $specimen = $authority->create('specimen', 'object-lifecycle', 'Physical object', [
            'serial_number' => 'SN-6',
            'condition_observations' => ['case' => 'fair'],
        ]);
        $product = $authority->create('product', 'listing-lifecycle', 'Listing', [
            'price' => 200.0,
            'availability' => 'listed',
        ]);
        $updated = $authority->update($product->canonicalId, [
            'price' => 250.0,
            'availability' => 'sold',
            'listing_title' => 'Sold listing',
        ], 1);
        $retired = $authority->retire($updated->canonicalId, 2);

        self::assertSame(250.0, $updated->payload['price']);
        self::assertFalse($retired->active());
        self::assertSame('SN-6', $repository->findByCanonicalId($specimen->canonicalId)?->payload['serial_number']);
        self::assertSame(['case' => 'fair'], $repository->findByCanonicalId($specimen->canonicalId)?->payload['condition_observations']);
        self::assertCount(1, $authority->list('specimen'));
        self::assertCount(0, $authority->list('product'));
        self::assertCount(1, $authority->list('product', true));
    }

    public function test_product_copy_is_not_knowledge_and_does_not_mutate_specimen(): void
    {
        $authority = $this->authority();
        $specimen = $authority->create('specimen', 'object-copy', 'Canonical object', [
            'serial_number' => 'SN-7',
            'technical_observations' => ['movement' => 'unknown'],
        ]);
        $product = $authority->create('product', 'listing-copy', 'Listing', [
            'listing_copy' => 'Brand Model runs perfectly.',
            'condition_copy' => 'Excellent condition',
        ]);

        $updated = $authority->update($product->canonicalId, [
            'listing_copy' => 'Different claim about Brand Model.',
            'condition_copy' => 'Like new',
        ], 1);

        self::assertSame('Different claim about Brand Model.', $updated->payload['listing_copy']);
        self::assertSame('Canonical object', $authority->list('specimen')[0]->canonicalName);
        self::assertSame('SN-7', $authority->list('specimen')[0]->payload['serial_number']);
        self::assertSame($specimen->canonicalId, $authority->list('specimen')[0]->canonicalId);
    }

    public function test_product_and_specimen_keep_distinct_public_routes(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $types = $this->types();
        $authority = new AuthorityService($repository, $types);
        $specimen = $authority->create('specimen', 'object-route', 'Object route', ['serial_number' => 'SN-8']);
        $product = $authority->create('product', 'listing-route', 'Listing route');
        $routes = new PublicRouteResolver($repository, $types);

        self::assertSame('/hien-vat/object-route/', $routes->path($specimen));
        self::assertSame('/san-pham/listing-route/', $routes->path($product));
        self::assertNotSame($routes->path($specimen), $routes->path($product));
    }

    private function authority(): AuthorityService
    {
        return new AuthorityService(new InMemoryAuthorityRepository(), $this->types());
    }

    private function types(): EntityTypeRegistry
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        return $types;
    }
}
