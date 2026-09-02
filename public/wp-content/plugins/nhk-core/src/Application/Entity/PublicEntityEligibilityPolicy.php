<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Application\Graph\StructuralContextQuery;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

final class PublicEntityEligibilityPolicy
{
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types, private PublicRouteResolver $routes, private ?StructuralContextQuery $contexts = null) {}

    public function evaluate(?AuthorityEntity $entity): PublicEligibilityResult
    {
        if (!$entity || !$this->types->has($entity->entityType)) return PublicEligibilityResult::blocked('UNKNOWN_TYPE');
        if (!$entity->active()) return PublicEligibilityResult::blocked('INACTIVE');

        $identity = (new PublicIdentityContract($this->types))->resolve($entity);
        if ($identity === null) return PublicEligibilityResult::blocked('INVALID_IDENTITY');

        $parentResult = $this->parentResult($entity);
        if ($parentResult !== null) {
            if (!$parentResult->eligible) return $parentResult;
            $result = $this->needsCompatibilityWarning($entity) ? PublicEligibilityResult::eligible()->withWarning('DATA_COMPATIBILITY_GAP') : PublicEligibilityResult::eligible();
        } else {
            $result = PublicEligibilityResult::eligible();
        }

        if ($this->routes->path($entity) === null) return PublicEligibilityResult::blocked('UNAVAILABLE');
        return $result;
    }

    private function parentResult(AuthorityEntity $entity): ?PublicEligibilityResult
    {
        if ($this->contexts !== null && in_array($entity->entityType, ['model', 'variant'], true)) {
            $context = $entity->entityType === 'model' ? $this->contexts->forModel($entity->canonicalId) : $this->contexts->forVariant($entity->canonicalId);
            if ($context->reasons !== []) return PublicEligibilityResult::blocked(...$context->reasons);
            return PublicEligibilityResult::eligible();
        }
        $field = match ($entity->entityType) {
            'model' => ['brand_uuid', 'brand'],
            'variant' => ['model_uuid', 'model'],
            default => null,
        };
        if ($field === null) return null;
        $value = $entity->payload[$field[0]] ?? null;
        if (!is_string($value) || !UuidCodec::isValid($value)) return PublicEligibilityResult::blocked('STRUCTURAL_PARENT_MISSING');
        $parent = $this->authority->findByCanonicalId($value);
        if (!$parent || $parent->entityType !== $field[1] || !$parent->active()) return PublicEligibilityResult::blocked('STRUCTURAL_PARENT_MISSING');
        return PublicEligibilityResult::eligible();
    }

    private function needsCompatibilityWarning(AuthorityEntity $entity): bool
    {
        if ($this->contexts === null) return true;
        $context = $entity->entityType === 'model' ? $this->contexts->forModel($entity->canonicalId) : $this->contexts->forVariant($entity->canonicalId);
        return $context->relationPath === [];
    }

}
