<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\{EntityProfilePublicDossier, EntityProfileReadFoundation, EntityProfileRegistry, EntityProfileResolver};
use NHK\Core\Contracts\Entity\EntityDossierReader;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class EntityProfilePublicDossierTest extends TestCase
{
    public function test_clock_type_uses_available_derived_brand_projection_when_supplied_by_reader(): void
    {
        $entity = new AuthorityEntity(UuidCodec::newV7(), 'classification', 'nhk:classification:clock-type.public-dossier', 'Đồng hồ công cộng', 1, ['family' => 'clock_type']);
        $foundation = new EntityProfileReadFoundation(new EntityProfileRegistry(), new EntityProfileResolver(), new PublicDossierFixtureReader());

        $result = (new EntityProfilePublicDossier($foundation))->forEntity($entity);

        self::assertSame('AVAILABLE_WITH_ITEMS', $result['public_dossier']['sections']['brands']['status']);
        self::assertSame('Odo', $result['public_dossier']['sections']['brands']['items'][0]['title']);
    }
}

final class PublicDossierFixtureReader implements EntityDossierReader
{
    public function forEntity(AuthorityEntity $entity): array
    {
        return [
            'status' => 'AVAILABLE',
            'identity' => ['type' => 'classification', 'name' => $entity->canonicalName],
            'knowledge' => ['status' => 'AVAILABLE', 'facets' => []],
            'media_gallery' => [],
            'relation_sections' => ['brands' => [['title' => 'Odo', 'canonical_id' => UuidCodec::newV7()]]],
            'clock_type_derived_brands' => ['status' => 'AVAILABLE_WITH_ITEMS', 'items' => [['title' => 'Odo', 'canonical_id' => UuidCodec::newV7()]], 'diagnostics' => []],
            'seo_projection' => [],
            'diagnostics' => [],
        ];
    }
}
