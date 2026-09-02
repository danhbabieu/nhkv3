<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Shared\Uuid\UuidCodec;

final class StructuralParentCompatibilityResolver
{
    public function __construct(private AuthorityRepository $authority) {}

    public function resolve(AuthorityEntity $entity): StructuralParentCompatibility
    {
        $field = match ($entity->entityType) {
            'model' => ['brand_uuid', 'brand'],
            'variant' => ['model_uuid', 'model'],
            default => null,
        };
        if ($field === null) {
            return new StructuralParentCompatibility($entity->entityType, $entity->canonicalId, '', null, 'UNSUPPORTED_CHILD_TYPE', warnings: []);
        }
        $value = $entity->payload[$field[0]] ?? null;
        if (!is_string($value) || !UuidCodec::isValid($value)) {
            return new StructuralParentCompatibility($entity->entityType, $entity->canonicalId, $field[1], null, $value === null ? 'MISSING_PARENT' : 'MALFORMED_REFERENCE');
        }
        $parent = $this->authority->findByCanonicalId($value);
        if ($parent === null || $parent->entityType !== $field[1]) {
            return new StructuralParentCompatibility($entity->entityType, $entity->canonicalId, $field[1], $value, 'PARENT_ENTITY_MISSING');
        }
        if (!$parent->active()) {
            return new StructuralParentCompatibility($entity->entityType, $entity->canonicalId, $field[1], $value, 'PARENT_INACTIVE');
        }
        return new StructuralParentCompatibility($entity->entityType, $entity->canonicalId, $field[1], $value, 'SAFE_UNIQUE_COMPATIBILITY_PARENT');
    }
}
