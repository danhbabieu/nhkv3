<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Contracts\Entity\EntityDossierReader;
use NHK\Core\Domain\Authority\AuthorityEntity;

/**
 * Shared read-only adapter for profile-aware Entity dossier packets. It does
 * not allocate Public Identity or write any Authority/Graph/domain state.
 */
final class EntityProfileReadFoundation
{
    public function __construct(private EntityProfileRegistry $profiles, private EntityProfileResolver $resolver, private EntityDossierReader $dossier) {}

    /** @return array<string,mixed> */
    public function forEntity(AuthorityEntity $entity): array
    {
        $resolution = $this->resolver->resolveProfile($entity);
        if (!$resolution->resolved()) {
            return [
                'status' => 'UNAVAILABLE',
                'reason' => 'PROFILE_UNRESOLVED',
                'profile_resolution' => $resolution->toArray(),
                'diagnostics' => [$resolution->diagnostic ?? 'PROFILE_UNRESOLVED'],
            ];
        }

        $definition = $this->profiles->get((string) $resolution->profileKey);
        if (!$definition instanceof EntityProfileDefinition) {
            return [
                'status' => 'UNAVAILABLE',
                'reason' => 'PROFILE_UNRESOLVED',
                'profile_resolution' => $resolution->toArray(),
                'diagnostics' => ['PROFILE_REGISTRY_UNAVAILABLE'],
            ];
        }

        $dossier = $this->dossier->forEntity($entity);
        $packet = is_array($dossier) ? $dossier : [];
        $packet['entity_profile'] = $definition->toArray();
        $packet['profile_resolution'] = $resolution->toArray();
        $packet['diagnostics'] = array_values(array_unique([
            ...((array) ($packet['diagnostics'] ?? [])),
            ...($resolution->diagnostic !== null ? [$resolution->diagnostic] : []),
        ]));
        return $packet;
    }
}
