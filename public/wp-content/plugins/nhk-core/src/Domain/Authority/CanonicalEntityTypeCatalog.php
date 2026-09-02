<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Authority;

final class CanonicalEntityTypeCatalog
{
    /** @return list<EntityTypeDefinition> */
    public static function definitions(): array
    {
        return [
            new EntityTypeDefinition('brand', 1, true, ['aliases', 'description', 'country', 'founded_year'], [], ['aliases' => 'array', 'description' => 'string', 'country' => 'string', 'founded_year' => 'int']),
            new EntityTypeDefinition('model', 1, true, ['brand_uuid', 'aliases', 'description', 'launch_year'], [], ['brand_uuid' => 'string', 'aliases' => 'array', 'description' => 'string', 'launch_year' => 'int'], ['brand_uuid' => 'uuid']),
            new EntityTypeDefinition('variant', 1, true, ['model_uuid', 'aliases', 'description', 'reference'], [], ['model_uuid' => 'string', 'aliases' => 'array', 'description' => 'string', 'reference' => 'string'], ['model_uuid' => 'uuid']),
            new EntityTypeDefinition('movement', 1, true, ['manufacturer', 'caliber', 'description', 'frequency_hz', 'jewels'], [], ['manufacturer' => 'string', 'caliber' => 'string', 'description' => 'string', 'frequency_hz' => 'float', 'jewels' => 'int']),
            new EntityTypeDefinition('music', 1, true, ['artist', 'album', 'description', 'release_year'], [], ['artist' => 'string', 'album' => 'string', 'description' => 'string', 'release_year' => 'int']),
            new EntityTypeDefinition('component', 1, true, ['kind', 'manufacturer', 'description'], [], ['kind' => 'string', 'manufacturer' => 'string', 'description' => 'string']),
            new EntityTypeDefinition('classification', 1, true, ['family', 'description'], [], ['family' => 'string', 'description' => 'string']),
            new EntityTypeDefinition('specimen', 1, true, ['model_uuid', 'serial_number', 'acquired_at', 'notes', 'physical_provenance', 'technical_observations', 'condition_observations'], [], ['model_uuid' => 'string', 'serial_number' => 'string', 'acquired_at' => 'string', 'notes' => 'string', 'physical_provenance' => 'array', 'technical_observations' => 'array', 'condition_observations' => 'array'], ['model_uuid' => 'uuid']),
            new EntityTypeDefinition('product', 1, true, ['vendor', 'url', 'price', 'currency', 'availability', 'listing_title', 'listing_copy', 'offer_state', 'inventory_state', 'listing_start_at', 'listing_end_at', 'commercial_lifecycle', 'condition_copy'], [], ['vendor' => 'string', 'url' => 'string', 'price' => 'float', 'currency' => 'string', 'availability' => 'string', 'listing_title' => 'string', 'listing_copy' => 'string', 'offer_state' => 'string', 'inventory_state' => 'string', 'listing_start_at' => 'string', 'listing_end_at' => 'string', 'commercial_lifecycle' => 'string', 'condition_copy' => 'string'], ['url' => 'url']),
        ];
    }

    public static function registerInto(EntityTypeRegistry $registry): void
    {
        foreach (self::definitions() as $definition) $registry->register($definition);
    }
}
