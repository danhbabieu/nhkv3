<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\{ComparisonPageQuery, EntityPageQuery};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class ComparisonPageQueryTest extends TestCase
{
    public function test_comparison_resolves_only_active_canonical_entity_references(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository(); $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'nhk:brand:odo', 'Odo', ['country' => 'Switzerland']);
        $retired = $authority->create('brand', 'nhk:brand:retired', 'Retired'); $authority->retire($retired->canonicalId, 1);
        $query = new ComparisonPageQuery(new EntityPageQuery($repository, $types));

        $result = $query->read('brand/' . $brand->stableKey, 'brand/' . $retired->stableKey);
        self::assertSame('Odo', $result['items']['left']['name']);
        self::assertNull($result['items']['right']);
        self::assertNull($query->read('not-a-reference', 'brand/missing')['items']['left']);
    }
}
