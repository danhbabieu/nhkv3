<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\PresentationNavigation;

use NHK\Core\Application\Presentation\NavigationTreeProjector;
use NHK\Core\Domain\PresentationNavigation\NavigationNode;
use NHK\Core\Domain\PresentationNavigation\NavigationPlacement;
use NHK\Core\Infrastructure\Presentation\InMemoryNavigationRepository;
use PHPUnit\Framework\TestCase;

final class NavigationTreeProjectorTest extends TestCase
{
    public function test_projects_sorted_roots_and_direct_children_only(): void
    {
        $parent = $this->node('parent', null, 20);
        $child = $this->node('child', 'parent', 10);
        $grandchild = $this->node('grandchild', 'child', 1);
        $otherRoot = $this->node('other-root', null, 10);
        $projector = $this->projector($parent, $child, $grandchild, $otherRoot);

        self::assertSame(['other-root', 'parent'], $this->ids($projector->roots('clock_type', NavigationPlacement::TYPE_INDEX)));
        self::assertSame(['child'], $this->ids($projector->children('clock_type', 'parent', NavigationPlacement::TYPE_INDEX)));
        self::assertSame([], $projector->children('clock_type', 'grandchild', NavigationPlacement::TYPE_INDEX));
    }

    public function test_filters_each_placement_independently_and_disabled_nodes(): void
    {
        $header = $this->node('header', null, 1, enabled: true, header: true, mobile: false);
        $mobile = $this->node('mobile', null, 2, enabled: true, header: false, mobile: true);
        $disabled = $this->node('disabled', null, 3, enabled: false, header: true, mobile: true);
        $projector = $this->projector($header, $mobile, $disabled);

        self::assertSame(['header'], $this->ids($projector->menu('clock_type', NavigationPlacement::HEADER_MENU)));
        self::assertSame(['mobile'], $this->ids($projector->menu('clock_type', NavigationPlacement::MOBILE_MENU)));
    }

    public function test_hidden_parent_child_reattaches_to_nearest_visible_ancestor_without_mutation(): void
    {
        $hidden = $this->node('hidden', null, 1, header: false);
        $visibleAncestor = $this->node('visible', 'hidden', 1, header: true);
        $child = $this->node('child', 'visible', 1, header: true);
        $repository = new InMemoryNavigationRepository([$hidden, $visibleAncestor, $child]);
        $projector = new NavigationTreeProjector($repository);

        self::assertSame(['visible'], $this->ids($projector->roots('clock_type', NavigationPlacement::HEADER_MENU)));
        self::assertSame(['child'], $this->ids($projector->children('clock_type', 'visible', NavigationPlacement::HEADER_MENU)));
        self::assertSame('hidden', $repository->storedNodes()['visible']->parentId);
    }

    public function test_child_becomes_root_when_no_visible_ancestor_remains_and_empty_hidden_parent_is_omitted(): void
    {
        $hidden = $this->node('hidden', null, 1, header: false);
        $child = $this->node('child', 'hidden', 1, header: true);
        $projector = $this->projector($hidden, $child);

        self::assertSame(['child'], $this->ids($projector->roots('clock_type', NavigationPlacement::HEADER_MENU)));
        self::assertSame([], $projector->breadcrumb('clock_type', 'child', NavigationPlacement::HEADER_MENU));
    }

    /** @return list<string> */
    private function ids(array $items): array
    {
        return array_values(array_map(static fn (array $item): string => (string) $item['canonical_uuid'], $items));
    }

    private function node(string $id, ?string $parentId, int $sort, bool $enabled = true, bool $header = true, bool $mobile = true): NavigationNode
    {
        return NavigationNode::fromArray([
            'id' => $id,
            'navigation_key' => 'clock_type',
            'canonical_type' => 'classification',
            'canonical_uuid' => $id,
            'parent_id' => $parentId,
            'sort_order' => $sort,
            'enabled' => $enabled,
            'show_in_type_index' => true,
            'show_in_header_menu' => $header,
            'show_in_mobile_menu' => $mobile,
            'show_in_sidebar' => true,
            'featured' => false,
            'revision' => 1,
        ]);
    }

    private function projector(NavigationNode ...$nodes): NavigationTreeProjector
    {
        return new NavigationTreeProjector(new InMemoryNavigationRepository($nodes));
    }
}
