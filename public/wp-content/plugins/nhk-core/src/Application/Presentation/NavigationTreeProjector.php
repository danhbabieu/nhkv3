<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

use NHK\Core\Contracts\PresentationNavigation\NavigationRepository;
use NHK\Core\Domain\PresentationNavigation\{NavigationNode, NavigationPlacement};

final class NavigationTreeProjector
{
    public function __construct(private NavigationRepository $repository) {}

    /** @return list<array<string,mixed>> */
    public function roots(string $navigationKey, string $placement): array
    {
        $projection = $this->projection($navigationKey, $placement);
        return array_values(array_filter($projection, static fn (array $item): bool => $item['projected_parent_id'] === null));
    }

    /** @return list<array<string,mixed>> */
    public function children(string $navigationKey, string $canonicalUuid, string $placement): array
    {
        $projection = $this->projection($navigationKey, $placement);
        return array_values(array_filter($projection, static fn (array $item): bool => $item['projected_parent_id'] === $canonicalUuid));
    }

    /** @return list<array<string,mixed>> */
    public function breadcrumb(string $navigationKey, string $canonicalUuid, string $placement): array
    {
        $projection = $this->projection($navigationKey, $placement);
        $byId = [];
        foreach ($projection as $item) $byId[(string) $item['canonical_uuid']] = $item;
        $result = [];
        $current = $byId[$canonicalUuid] ?? null;
        while (is_array($current) && $current['projected_parent_id'] !== null) {
            $parent = $byId[(string) $current['projected_parent_id']] ?? null;
            if (!is_array($parent)) break;
            array_unshift($result, $parent);
            $current = $parent;
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function menu(string $navigationKey, string $placement): array
    {
        return $this->projection($navigationKey, $placement);
    }

    /** @return list<array<string,mixed>> */
    private function projection(string $navigationKey, string $placement): array
    {
        NavigationPlacement::field($placement);
        $nodes = array_values(array_filter($this->repository->list($navigationKey), static fn (NavigationNode $node): bool => $node->visibleAt($placement)));
        usort($nodes, static fn (NavigationNode $left, NavigationNode $right): int => [$left->sortOrder, $left->id] <=> [$right->sortOrder, $right->id]);
        $visible = [];
        foreach ($nodes as $node) $visible[$node->canonicalUuid] = $node;
        $projected = [];
        foreach ($nodes as $node) {
            $parent = $this->nearestVisibleParent($node, $visible, $nodes);
            $projected[] = [
                'id' => $node->id,
                'navigation_key' => $node->navigationKey,
                'canonical_type' => $node->canonicalType,
                'canonical_uuid' => $node->canonicalUuid,
                'stored_parent_id' => $node->parentId,
                'projected_parent_id' => $parent,
                'sort_order' => $node->sortOrder,
                'featured' => $node->featured,
                'revision' => $node->revision,
            ];
        }
        usort($projected, static fn (array $left, array $right): int => [(int) $left['sort_order'], (string) $left['canonical_uuid']] <=> [(int) $right['sort_order'], (string) $right['canonical_uuid']]);
        return $projected;
    }

    /** @param array<string,NavigationNode> $visible @param list<NavigationNode> $all */
    private function nearestVisibleParent(NavigationNode $node, array $visible, array $all): ?string
    {
        $byId = [];
        foreach ($all as $candidate) $byId[$candidate->id] = $candidate;
        $parentId = $node->parentId;
        $seen = [];
        while ($parentId !== null && !isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $parent = $byId[$parentId] ?? null;
            if ($parent === null) return null;
            if (isset($visible[$parent->canonicalUuid])) return $parent->canonicalUuid;
            $parentId = $parent->parentId;
        }
        return null;
    }
}
