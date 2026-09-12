<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Contracts\Entity\EntityDossierReader;
use NHK\Core\Domain\Authority\AuthorityEntity;

/** Read-only operator card assembled from the profile and dossier owners. */
final class EntityProfileAdminProjection
{
    public function __construct(
        private EntityProfileRegistry $profiles = new EntityProfileRegistry(),
        private EntityProfileResolver $resolver = new EntityProfileResolver(),
        private ?EntityDossierReader $dossier = null,
    ) {}

    /** @return array<string,mixed> */
    public function forEntity(AuthorityEntity $entity): array
    {
        $resolution = $this->resolver->resolveProfile($entity);
        if (!$resolution->resolved()) {
            return [
                'status' => 'UNAVAILABLE',
                'reason' => 'PROFILE_UNRESOLVED',
                'diagnostics' => [$resolution->diagnostic ?? 'PROFILE_UNRESOLVED'],
                'profile_resolution' => $resolution->toArray(),
            ];
        }

        $definition = $this->profiles->get((string) $resolution->profileKey);
        if (!$definition instanceof EntityProfileDefinition) {
            return ['status' => 'UNAVAILABLE', 'reason' => 'PROFILE_REGISTRY_UNAVAILABLE', 'diagnostics' => ['PROFILE_REGISTRY_UNAVAILABLE']];
        }

        $packet = $this->dossier?->forEntity($entity);
        $packet = is_array($packet) ? $packet : [];
        $relations = is_array($packet['relation_sections'] ?? null) ? $packet['relation_sections'] : [];
        $profile = $definition->toArray();
        $diagnostics = array_values(array_unique([
            ...((array) ($packet['diagnostics'] ?? [])),
            ...($resolution->diagnostic !== null ? [$resolution->diagnostic] : []),
        ]));

        return [
            'status' => ($packet['status'] ?? 'AVAILABLE') === 'BLOCKED' ? 'BLOCKED' : 'AVAILABLE',
            'profile' => $profile,
            'profile_resolution' => $resolution->toArray(),
            'identity' => [
                'name' => $entity->canonicalName,
                'profile' => $definition->key,
                'entity_type' => $entity->entityType,
                'family' => is_string($entity->payload['family'] ?? null) ? $entity->payload['family'] : null,
                'uuid' => $entity->canonicalId,
                'stable_key' => $entity->stableKey,
                'revision' => $entity->revision,
                'state' => $entity->state->name,
            ],
            'aliases' => $this->safeStringList($entity->payload['aliases'] ?? []),
            'description' => is_string($entity->payload['description'] ?? null) ? $entity->payload['description'] : '',
            'sections' => [
                'knowledge' => $this->section($packet['knowledge'] ?? null, 'knowledge'),
                'media' => $this->section($packet['media_gallery'] ?? null, 'media'),
                'video' => $this->section($relations['videos'] ?? null, 'video'),
                'articles' => $this->section($relations['articles'] ?? null, 'articles'),
                'models' => $this->section($relations['models'] ?? null, 'models'),
                'variants' => $this->section($relations['variants'] ?? null, 'variants'),
                'specimens' => $this->section($relations['specimens'] ?? null, 'specimens'),
                'products' => $this->section($relations['products'] ?? null, 'products'),
                'brands' => $this->section($relations['brands'] ?? null, 'brands'),
                'subtypes' => $this->section($relations['classifications'] ?? null, 'subtypes'),
                'parent' => $this->parentSection($entity),
                'public_identity' => $this->section($packet['identity'] ?? null, 'public_identity'),
                'seo' => $this->section($packet['seo_projection'] ?? null, 'seo'),
                'diagnostics' => $this->section($diagnostics, 'diagnostics'),
            ],
        ];
    }

    /** @return array{state:string,items:list<mixed>,count:int} */
    private function section(mixed $value, string $key): array
    {
        if (is_array($value) && isset($value['status']) && is_string($value['status'])) {
            $status = strtoupper($value['status']);
            if ($status === 'BLOCKED') return ['state' => 'BLOCKED', 'items' => [], 'count' => 0];
            if (in_array($status, ['UNAVAILABLE', 'UNAVAILABLE_IMPLEMENTATION_GAP'], true)) return ['state' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'items' => [], 'count' => 0];
            $value = $value['items'] ?? [];
        }
        if ($value === null) return ['state' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'items' => [], 'count' => 0];
        if ($key === 'knowledge' && is_array($value)) {
            $items = is_array($value['facets'] ?? null) ? array_values($value['facets']) : [];
            $count = max(count($items), (int) ($value['claim_count'] ?? 0));
            return ['state' => $count > 0 ? 'AVAILABLE_WITH_ITEMS' : 'AVAILABLE_EMPTY', 'items' => $items, 'count' => $count];
        }
        $items = array_is_list($value) ? array_values($value) : [$value];
        return ['state' => $items !== [] ? 'AVAILABLE_WITH_ITEMS' : 'AVAILABLE_EMPTY', 'items' => $items, 'count' => count($items)];
    }

    /** @return array{state:string,items:list<mixed>,count:int} */
    private function parentSection(AuthorityEntity $entity): array
    {
        $parent = $entity->payload['parent'] ?? null;
        return $parent === null ? ['state' => 'AVAILABLE_EMPTY', 'items' => [], 'count' => 0] : $this->section([$parent], 'parent');
    }

    /** @return list<string> */
    private function safeStringList(mixed $values): array
    {
        if (!is_array($values)) return [];
        return array_values(array_filter(array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $values), static fn (string $value): bool => $value !== ''));
    }
}
