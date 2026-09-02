<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};

final class PublicIdentityContract
{
    public function __construct(private EntityTypeRegistry $types) {}

    /** @return array{type:string,name:string,slug:string}|null Public projection only. */
    public function resolve(AuthorityEntity $entity): ?array
    {
        if (!$this->types->has($entity->entityType)) return null;
        $slug = PublicRouteResolver::slug($entity->canonicalName);
        if ($slug === '') return null;
        return [
            'type' => $entity->entityType,
            'name' => $entity->canonicalName,
            'slug' => $slug,
        ];
    }

    /** @return array<string,mixed> Public payload excludes internal relationship identifiers. */
    public function payload(AuthorityEntity $entity): array
    {
        if (!$this->types->has($entity->entityType)) return [];
        $definition = $this->types->get($entity->entityType);
        $payload = array_intersect_key($entity->payload, array_fill_keys($definition->allowedFields, true));
        foreach (array_keys($payload) as $field) {
            if (str_ends_with($field, '_uuid') || $field === 'id' || $field === 'stable_key') unset($payload[$field]);
        }
        return $payload;
    }
}
