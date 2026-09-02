<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\{EntityPageQuery, PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Shared\Uuid\UuidCodec;
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
        $all = $query->archive('brand', 1, 24);
        self::assertNotContains($retired->canonicalId, array_column($all['items'], 'id'));
        self::assertSame($first->canonicalId, $query->detail('brand', 'odo')['id']);
        self::assertNull($query->detail('brand', 'retired'));
        self::assertNull($query->detail('model', 'odo'));
    }

    public function test_legacy_public_slug_resolves_only_to_one_active_entity(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($repository = new InMemoryAuthorityRepository(), $types);
        $brand = $authority->create('brand', 'nhk:brand:o-do', 'Odo');
        $query = new EntityPageQuery($repository, $types);

        self::assertSame($brand->stableKey, $query->stableKeyForPublicSlug('brand', 'odo'));
        $authority->create('brand', 'nhk:brand:odo-duplicate', 'Odo');
        self::assertNull($query->stableKeyForPublicSlug('brand', 'odo'));
        self::assertNull($query->stableKeyForPublicSlug('model', 'odo'));
        $retired = $authority->create('brand', 'nhk:brand:retired-odo', 'Retired Odo');
        $authority->retire($retired->canonicalId, 1);
        self::assertNull($query->stableKeyForPublicSlug('brand', 'retired-odo'));
    }

    public function test_public_entity_payload_is_limited_to_registered_reader_fields(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $entity = new AuthorityEntity(UuidCodec::newV7(), 'brand', 'public-payload', 'Public payload', 1, ['country' => 'Switzerland', 'private_note' => 'internal']);
        $repository->create($entity);
        $query = new EntityPageQuery($repository, $types);

        self::assertSame(['country' => 'Switzerland'], $query->detail('brand', 'public-payload')['payload']);
        self::assertSame(0, $query->archive('brand', 1, 24, 'internal')['total']);
        self::assertSame(1, $query->archive('brand', 1, 24, 'Switzerland')['total']);
    }

    public function test_entity_page_query_delegates_public_collection_membership_to_the_shared_boundary(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($repository, $types);
        $authority->create('brand', 'visible', 'Visible');
        $authority->create('brand', 'reserved', 'Video');
        $routes = new PublicRouteResolver($repository, $types);
        $collection = new PublicEntityCollectionQuery($repository, $types, new PublicIdentityContract($types), new PublicEntityEligibilityPolicy($repository, $types, $routes), $routes);
        $query = new EntityPageQuery($repository, $types, null, null, $routes, $collection);

        self::assertSame(1, $query->archive('brand')['total']);
        self::assertNull($query->detail('brand', 'reserved'));
    }
}
