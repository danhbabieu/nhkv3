<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\EntityPageQuery;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class EntityPageQueryTest extends TestCase
{
    public function test_archive_and_detail_are_type_scoped_active_and_paginated(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($repository = new InMemoryAuthorityRepository(), $types);
        $first = $authority->create('brand', 'odo', 'Odo', ['country' => 'Switzerland']);
        $authority->create('brand', 'cartier', 'Cartier');
        $retired = $authority->create('brand', 'retired', 'Retired'); $authority->retire($retired->canonicalId, 1);
        $query = new EntityPageQuery($repository, $types);
        $archive = $query->archive('brand', 1, 1, 'odo');
        self::assertSame(1, $archive['total']); self::assertSame($first->canonicalId, $archive['items'][0]['id']);
        self::assertSame($first->canonicalId, $query->detail('brand', 'odo')['id']);
        self::assertNull($query->detail('brand', 'retired'));
        self::assertNull($query->detail('model', 'odo'));
    }
}
