<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Graph\{ClockTypeDerivedRelationshipQuery, ClockTypeHierarchyProjection};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;

/** Read-only Clock-Type enrichment over the shared Entity dossier packet. */
final class ClockTypeDossierProjection
{
    public function __construct(
        private ClockTypeHierarchyProjection $hierarchy,
        private ClockTypeDerivedRelationshipQuery $derivedBrands,
        private EntityProfileResolver $profiles = new EntityProfileResolver(),
        private ?PublicRouteResolver $routes = null,
        private ?PublicIdentityContract $identities = null,
        private ?AuthorityRepository $authority = null,
    ) {}

    /** @param array<string,mixed> $dossier @return array<string,mixed> */
    public function forEntity(AuthorityEntity $entity, array $dossier): array
    {
        $profile = $this->profiles->resolveProfile($entity);
        if ($entity->entityType !== 'classification' || $profile->status !== 'RESOLVED' || $profile->profileKey !== 'clock_type') return $dossier;
        if (($dossier['status'] ?? '') !== 'AVAILABLE') return $dossier;

        $hierarchy = $this->hierarchy->project($entity->canonicalId);
        $sections = is_array($dossier['relation_sections'] ?? null) ? $dossier['relation_sections'] : [];
        $derivedBrandResult = $this->derivedBrands->forClockType($entity->canonicalId);
        $brands = $this->brandItems($derivedBrandResult);
        if ($brands !== []) $sections['brands'] = $this->merge($sections['brands'] ?? [], $brands);

        $dossier['relation_sections'] = $sections;
        $dossier['clock_type_hierarchy'] = $hierarchy;
        $dossier['clock_type_derived_brands'] = [
            'status' => $this->status((string) ($derivedBrandResult['status'] ?? 'UNAVAILABLE')),
            'items' => $brands,
            'diagnostics' => array_values((array) ($derivedBrandResult['diagnostics'] ?? [])),
        ];
        $dossier['media_context'] = [
            'direct' => $this->mediaBucket($dossier['media_gallery'] ?? []),
            'derived' => $this->mediaBucket($sections['media'] ?? []),
        ];
        $diagnostics = is_array($dossier['diagnostics'] ?? null) ? $dossier['diagnostics'] : [];
        $diagnostics = [...$diagnostics, ...((array) ($hierarchy['diagnostics'] ?? []))];
        $dossier['diagnostics'] = array_values(array_unique($diagnostics));
        $dossier['profile'] = (new SemanticProfileComposer())->compose('clock_type', $dossier);
        return $dossier;
    }

    /** @param array<string,mixed> $result @return list<array<string,mixed>> */
    private function brandItems(array $result): array
    {
        $items = [];
        foreach ((array) ($result['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $id = trim((string) ($item['canonical_id'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($id === '' || $name === '') continue;
            $path = is_array($item['best_path'] ?? null) ? $item['best_path'] : [];
            $predicates = array_values(array_filter(array_map(static fn (mixed $hop): string => is_array($hop) ? trim((string) ($hop['predicate'] ?? '')) : '', $path), static fn (string $predicate): bool => $predicate !== ''));
            $viaTypes = in_array('variant_of', $predicates, true) ? ['model', 'variant'] : ['model'];
            $projected = [
                'canonical_id' => $id,
                'type' => (string) ($item['entity_type'] ?? 'brand'),
                'title' => $name,
                'name' => $name,
                'origin' => [
                    'kind' => (string) ($item['relationship_class'] ?? 'DERIVED'),
                    'hop_count' => count($predicates),
                    'predicates' => $predicates,
                    'via_types' => $viaTypes,
                ],
            ];
            $brand = $this->routes === null || $this->identities === null ? null : $this->authorityFind($id);
            if ($brand instanceof AuthorityEntity && $this->identities->resolvePersisted($brand) !== null) {
                $path = $this->routes->path($brand);
                if ($path !== null) $projected['url'] = $path;
            }
            $items[] = $projected;
        }
        return $items;
    }

    private function authorityFind(string $id): ?AuthorityEntity
    {
        return $this->authority?->findByCanonicalId($id);
    }

    /** @param list<mixed> $existing @param list<array<string,mixed>> $incoming @return list<array<string,mixed>> */
    private function merge(array $existing, array $incoming): array
    {
        $merged = [];
        foreach ([...$existing, ...$incoming] as $item) {
            if (!is_array($item)) continue;
            $id = trim((string) ($item['canonical_id'] ?? $item['url'] ?? $item['title'] ?? ''));
            if ($id === '') continue;
            if (!isset($merged[$id]) || $this->rank($item) < $this->rank($merged[$id])) $merged[$id] = $item;
        }
        $items = array_values($merged);
        usort($items, fn (array $left, array $right): int => [(string) ($left['title'] ?? ''), (string) ($left['canonical_id'] ?? '')] <=> [(string) ($right['title'] ?? ''), (string) ($right['canonical_id'] ?? '')]);
        return $items;
    }

    /** @param array<string,mixed> $item @return array{int,int} */
    private function rank(array $item): array
    {
        return [($item['origin']['kind'] ?? '') === 'DIRECT' ? 0 : 1, (int) ($item['origin']['hop_count'] ?? 99)];
    }

    /** @param mixed $items @return array<string,mixed> */
    private function mediaBucket(mixed $items): array
    {
        $values = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
        return ['status' => $values === [] ? 'AVAILABLE_EMPTY' : 'AVAILABLE_WITH_ITEMS', 'items' => $values];
    }

    private function status(string $status): string
    {
        return match ($status) {
            'AVAILABLE_WITH_ITEMS' => 'AVAILABLE_WITH_ITEMS',
            'AVAILABLE_EMPTY' => 'AVAILABLE_EMPTY',
            'BLOCKED' => 'BLOCKED',
            default => 'UNAVAILABLE_IMPLEMENTATION_GAP',
        };
    }
}
