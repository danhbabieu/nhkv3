<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Domain\Authority\AuthorityEntity;

/** Resolves at most one profile from canonical entity metadata only. */
final class EntityProfileResolver
{
    public function __construct(private EntityProfileRegistry $profiles = new EntityProfileRegistry()) {}

    public function resolveProfile(AuthorityEntity $entity): EntityProfileResolution
    {
        if ($entity->entityType === 'brand') {
            if (!$this->profiles->get('brand') instanceof EntityProfileDefinition) return $this->unresolved('PROFILE_REGISTRY_UNAVAILABLE');
            return new EntityProfileResolution(EntityProfileResolution::RESOLVED, 'brand', 'not_applicable');
        }

        if ($entity->entityType !== 'classification') {
            return $this->unresolved('PROFILE_NOT_REGISTERED');
        }

        $profile = $this->profiles->get('clock_type');
        if (!$profile instanceof EntityProfileDefinition) return $this->unresolved('PROFILE_REGISTRY_UNAVAILABLE');
        $canonicalFamily = $profile->matchingRule['family'] ?? null;
        if ($canonicalFamily !== 'clock_type') return $this->unresolved('PROFILE_REGISTRY_INVALID');

        $family = $entity->payload['family'] ?? null;
        if (!is_string($family) || trim($family) === '') return $this->unresolved('CLASSIFICATION_FAMILY_UNRESOLVED');
        $family = trim($family);
        if ($family === $canonicalFamily) {
            return new EntityProfileResolution(EntityProfileResolution::RESOLVED, $profile->key, 'canonical', $family, $canonicalFamily);
        }
        if (in_array($family, $profile->readFamilyAliases, true)) {
            return new EntityProfileResolution(EntityProfileResolution::COMPATIBILITY_READ, $profile->key, 'legacy-compatible', $family, $canonicalFamily, 'DATA_COMPATIBILITY_GAP');
        }
        if ($family === 'case_form') return $this->unresolved('FAMILY_NOT_CLOCK_TYPE');
        return $this->unresolved('CLASSIFICATION_FAMILY_UNRESOLVED');
    }

    private function unresolved(string $diagnostic): EntityProfileResolution
    {
        return new EntityProfileResolution(EntityProfileResolution::PROFILE_UNRESOLVED, null, 'unresolved', diagnostic: $diagnostic);
    }
}
