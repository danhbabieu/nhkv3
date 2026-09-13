<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Application\Entity\{EntityProfileResolver, EntityProfileResolution};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\NodeReference;

/** Read-only, profile-aware projection of a Clock-Type hierarchy. */
final class ClockTypeHierarchyProjection
{
    private const MAX_VISITED = 200;

    public function __construct(
        private AuthorityRepository $authority,
        private GraphService $graph,
        private EntityProfileResolver $profiles = new EntityProfileResolver(),
    ) {}

    /** @return array<string,mixed> */
    public function project(string $clockTypeUuid): array
    {
        $root = $this->authority->findByCanonicalId($clockTypeUuid);
        if (!$this->isCanonicalClockType($root)) {
            return ['status' => 'BLOCKED', 'parent' => [], 'children' => [], 'diagnostics' => ['CLOCK_TYPE_NOT_AVAILABLE']];
        }

        try {
            $diagnostics = [];
            $parent = $this->parent($root, $diagnostics);
            $children = $this->children($root, $diagnostics);
            if ($this->hasCycle($root->canonicalId)) {
                $diagnostics[] = 'CLASSIFICATION_HIERARCHY_CYCLE';
                return ['status' => 'BLOCKED', 'parent' => $parent, 'children' => $children, 'diagnostics' => array_values(array_unique($diagnostics))];
            }

            return [
                'status' => 'AVAILABLE',
                'parent' => $parent,
                'children' => $children,
                'diagnostics' => array_values(array_unique($diagnostics)),
            ];
        } catch (\Throwable) {
            return ['status' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'parent' => [], 'children' => [], 'diagnostics' => ['CLOCK_TYPE_HIERARCHY_READ_UNAVAILABLE']];
        }
    }

    /** @param list<string> $diagnostics @return array<string,mixed> */
    private function parent(AuthorityEntity $root, array &$diagnostics): array
    {
        $items = $this->graph->findOutgoing(new NodeReference('classification', $root->canonicalId), 'subtype_of', 0, 200, false, 'classification')['items'] ?? [];
        $parents = [];
        foreach ($items as $edge) {
            if (!is_object($edge) || !method_exists($edge, 'isActive') || !$edge->isActive()) continue;
            $candidate = $this->authority->findByCanonicalId($edge->target->reference->endpoint_key);
            if (!$this->isCanonicalClockType($candidate)) {
                $diagnostics[] = 'INVALID_CLOCK_TYPE_PARENT';
                continue;
            }
            $parents[$candidate->canonicalId] = $this->item($candidate, 'PARENT_TYPE');
        }
        if (count($parents) > 1) $diagnostics[] = 'CLOCK_TYPE_PARENT_AMBIGUOUS';
        $values = array_values($parents);
        usort($values, $this->sort(...));
        return $values[0] ?? [];
    }

    /** @param list<string> $diagnostics @return list<array<string,mixed>> */
    private function children(AuthorityEntity $root, array &$diagnostics): array
    {
        $items = $this->graph->findIncoming(new NodeReference('classification', $root->canonicalId), 'subtype_of', 0, 200, false, 'classification')['items'] ?? [];
        $children = [];
        foreach ($items as $edge) {
            if (!is_object($edge) || !method_exists($edge, 'isActive') || !$edge->isActive()) continue;
            $candidate = $this->authority->findByCanonicalId($edge->source->reference->endpoint_key);
            if (!$this->isCanonicalClockType($candidate)) {
                $diagnostics[] = 'INVALID_CLOCK_TYPE_CHILD';
                continue;
            }
            $children[$candidate->canonicalId] = $this->item($candidate, 'CHILD_TYPE');
        }
        $values = array_values($children);
        usort($values, $this->sort(...));
        return $values;
    }

    private function hasCycle(string $rootId): bool
    {
        $current = $rootId;
        $visited = [];
        for ($step = 0; $step < self::MAX_VISITED; $step++) {
            if (isset($visited[$current])) return $current === $rootId;
            $visited[$current] = true;
            $page = $this->graph->findOutgoing(new NodeReference('classification', $current), 'subtype_of', 0, 2, false, 'classification');
            $next = null;
            foreach ((array) ($page['items'] ?? []) as $edge) {
                if (is_object($edge) && method_exists($edge, 'isActive') && $edge->isActive()) {
                    $next = (string) $edge->target->reference->endpoint_key;
                    break;
                }
            }
            if ($next === null || $next === '') return false;
            $current = $next;
        }
        return true;
    }

    private function isCanonicalClockType(?AuthorityEntity $entity): bool
    {
        if (!$entity instanceof AuthorityEntity || !$entity->active() || $entity->entityType !== 'classification') return false;
        $profile = $this->profiles->resolveProfile($entity);
        return $profile->status === EntityProfileResolution::RESOLVED && $profile->profileKey === 'clock_type';
    }

    /** @return array<string,mixed> */
    private function item(AuthorityEntity $entity, string $kind): array
    {
        return ['canonical_id' => $entity->canonicalId, 'name' => $entity->canonicalName, 'family' => 'clock_type', 'revision' => $entity->revision, 'kind' => $kind];
    }

    private function sort(array $left, array $right): int
    {
        return [(string) ($left['name'] ?? ''), (string) ($left['canonical_id'] ?? '')] <=> [(string) ($right['name'] ?? ''), (string) ($right['canonical_id'] ?? '')];
    }
}
