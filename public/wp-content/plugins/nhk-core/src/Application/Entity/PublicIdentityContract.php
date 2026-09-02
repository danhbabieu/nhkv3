<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};

final class PublicIdentityContract
{
    public function __construct(private EntityTypeRegistry $types) {}

    /** @return array{id:string,type:string,stable_key:string,name:string,slug:string}|null */
    public function resolve(AuthorityEntity $entity): ?array
    {
        if (!$this->types->has($entity->entityType)) return null;
        $slug = PublicRouteResolver::slug($entity->canonicalName);
        if ($slug === '') return null;
        return [
            'id' => $entity->canonicalId,
            'type' => $entity->entityType,
            'stable_key' => $entity->stableKey,
            'name' => $entity->canonicalName,
            'slug' => $slug,
        ];
    }
}
