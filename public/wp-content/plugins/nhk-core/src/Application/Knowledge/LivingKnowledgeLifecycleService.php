<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Application\Projection\ProjectionInvalidationService;
use NHK\Core\Contracts\Projection\{ProjectionDependencyIndex, ProjectionRevisionStore};

/** Owner-neutral stale/regeneration orchestration over the existing projection stores. */
final class LivingKnowledgeLifecycleService
{
    private array $states = [];

    public function __construct(private ProjectionDependencyIndex $dependencies, private ProjectionRevisionStore $store)
    {
    }

    public function registerConsumed(array $owner, array $dependencies): void
    {
        $node = trim((string) ($owner['id'] ?? $owner['node_uuid'] ?? ''));
        $type = trim((string) ($owner['type'] ?? $owner['node_type'] ?? ''));
        if ($node === '' || $type === '') throw new \InvalidArgumentException('OWNER_DEPENDENCY_IDENTITY_REQUIRED');
        foreach ($dependencies as $dependency) {
            if (!is_array($dependency) || trim((string) ($dependency['id'] ?? '')) === '' || trim((string) ($dependency['kind'] ?? '')) === '') continue;
            $this->dependencies->add([
                'kind' => (string) $dependency['kind'], 'id' => (string) $dependency['id'], 'node_uuid' => $node, 'node_type' => $type,
                'section_key' => (string) ($dependency['surface'] ?? $dependency['section_key'] ?? 'owner'), 'dependency_revision' => (int) ($dependency['revision'] ?? $dependency['dependency_revision'] ?? 1),
                'scope' => (string) ($dependency['scope'] ?? 'direct'), 'graph_distance' => (int) ($dependency['graph_distance'] ?? 0), 'trace' => (array) ($dependency['trace'] ?? []), 'publicity' => (string) ($dependency['publicity'] ?? 'public'),
            ]);
        }
        $this->states[$node] = ['state' => 'CURRENT', 'owner_id' => $node, 'owner_type' => $type];
    }

    public function knowledgeChanged(string $kind, string $id, int $revision): array
    {
        $result = (new ProjectionInvalidationService($this->dependencies, $this->store))->invalidate($kind, $id, $revision);
        $owners = [];
        foreach ((array) ($result['impacted'] ?? []) as $item) {
            $ownerId = trim((string) ($item['node_uuid'] ?? ''));
            if ($ownerId === '') continue;
            $owner = ['owner_id' => $ownerId, 'owner_type' => (string) ($item['node_type'] ?? 'generic'), 'state' => 'STALE', 'status' => 'EDITORIAL_REGENERATION_AVAILABLE', 'changed_dependency' => ['kind' => $kind, 'id' => $id, 'revision' => $revision], 'governed_apply_required' => true];
            $owners[$ownerId] = $owner;
            $this->states[$ownerId] = $owner + ['state' => 'STALE'];
        }
        return ['status' => $owners === [] ? ($result['status'] ?? 'invalidated') : 'EDITORIAL_REGENERATION_AVAILABLE', 'owners' => array_values($owners), 'invalidation' => $result, 'public_mutation' => false];
    }

    public function regenerate(array $owner, array $input, callable $universalCore, callable $consumer): array
    {
        $pack = $universalCore($input);
        $proposal = $consumer($pack);
        return ['status' => 'REGENERATION_PREVIEW_READY', 'owner' => $owner, 'pack' => $pack, 'proposal' => $proposal, 'governed_apply_required' => true];
    }

    public function preview(array $owner, array $current, array $proposed): RegenerationPreview
    {
        $old = $this->dependencyMap((array) ($current['dependencies'] ?? []));
        $new = $this->dependencyMap((array) ($proposed['dependencies'] ?? []));
        $oldIds = array_keys($old); $newIds = array_keys($new);
        $oldSurfaces = (array) ($current['surfaces'] ?? []); $newSurfaces = (array) ($proposed['surfaces'] ?? []);
        $wording = [];
        foreach (array_unique(array_merge(array_keys($oldSurfaces), array_keys($newSurfaces))) as $surface) if (($oldSurfaces[$surface] ?? '') !== ($newSurfaces[$surface] ?? '')) $wording[$surface] = ['from' => $oldSurfaces[$surface] ?? '', 'to' => $newSurfaces[$surface] ?? ''];
        return new RegenerationPreview(['owner_id' => (string) ($owner['id'] ?? ''), 'owner_type' => (string) ($owner['type'] ?? 'generic'), 'current_revision' => (int) ($current['revision'] ?? 0), 'proposed_revision' => (int) ($proposed['revision'] ?? 0), 'changed_dependencies' => array_values(array_unique(array_merge(array_diff($oldIds, $newIds), array_diff($newIds, $oldIds)))), 'factual_additions' => array_values(array_diff($newIds, $oldIds)), 'factual_removals' => array_values(array_diff($oldIds, $newIds)), 'wording_changes' => $wording, 'affected_surfaces' => array_keys($wording), 'quality' => (array) ($proposed['quality'] ?? ['status' => 'unavailable']), 'warnings' => (array) ($proposed['warnings'] ?? []), 'blockers' => (array) ($proposed['blockers'] ?? []), 'governed_apply_required' => true]);
    }

    public function reconcileReadback(array $owner, array $readback): array
    {
        $node = trim((string) ($owner['id'] ?? ''));
        if (($readback['status'] ?? '') !== 'verified') return $this->states[$node] = ['owner_id' => $node, 'owner_type' => (string) ($owner['type'] ?? 'generic'), 'state' => 'STALE', 'reason' => 'CANONICAL_READBACK_UNAVAILABLE'];
        foreach ((array) ($readback['dependencies'] ?? []) as $dependency) $this->registerConsumed($owner, [$dependency]);
        return $this->states[$node] = ['owner_id' => $node, 'owner_type' => (string) ($owner['type'] ?? 'generic'), 'state' => 'CURRENT', 'canonical_readback' => true];
    }

    public function state(string $ownerId): array { return $this->states[$ownerId] ?? ['owner_id' => $ownerId, 'state' => 'UNKNOWN']; }

    private function dependencyMap(array $dependencies): array
    {
        $map = [];
        foreach ($dependencies as $dependency) if (is_array($dependency) && trim((string) ($dependency['id'] ?? '')) !== '') $map[(string) $dependency['id']] = $dependency;
        return $map;
    }
}
