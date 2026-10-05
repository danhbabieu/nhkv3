<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Dictionary\DictionaryRelationFacetRegistry;
use PHPUnit\Framework\TestCase;
final class DictionaryRelationFacetRegistryTest extends TestCase
{
    public function test_exact_type_and_classification_family_mapping_is_deterministic(): void
    {
        $registry = new DictionaryRelationFacetRegistry();
        self::assertSame('brands', $registry->facetFor('brand'));
        self::assertSame('countries', $registry->facetFor('classification', 'country'));
        self::assertSame('clock_types', $registry->facetFor('classification', 'clock_type'));
        self::assertNull($registry->facetFor('classification', 'unknown'));
        self::assertSame($registry->hash(), (new DictionaryRelationFacetRegistry())->hash());
    }
    public function test_duplicate_or_conflicting_facet_definition_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DictionaryRelationFacetRegistry())->register(['facet_key'=>'brands','allowed_target_types'=>['movement']]);
    }
}
