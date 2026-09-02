<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\PublicIdentityContract;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class PublicIdentityContractTest extends TestCase
{
    public function test_identity_returns_canonical_display_fields_for_a_registered_active_entity(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $entity = (new AuthorityService(new InMemoryAuthorityRepository(), $types))->create('brand', 'nhk:brand:odo', 'Ô Đô');

        self::assertSame([
            'id' => $entity->canonicalId,
            'type' => 'brand',
            'stable_key' => 'nhk:brand:odo',
            'name' => 'Ô Đô',
            'slug' => 'o-do',
        ], (new PublicIdentityContract($types))->resolve($entity));
    }

    public function test_identity_rejects_an_unregistered_entity_type(): void
    {
        $types = new EntityTypeRegistry();
        $entity = new \NHK\Core\Domain\Authority\AuthorityEntity(
            \NHK\Core\Shared\Uuid\UuidCodec::newV7(),
            'unknown',
            'unknown:item',
            'Unknown',
            1,
            [],
        );

        self::assertNull((new PublicIdentityContract($types))->resolve($entity));
    }
}
