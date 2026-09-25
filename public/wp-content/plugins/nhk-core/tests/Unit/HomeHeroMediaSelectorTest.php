<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Home\HomeHeroMediaSelector;
use PHPUnit\Framework\TestCase;

final class HomeHeroMediaSelectorTest extends TestCase
{
    public function test_manual_order_is_preserved_and_auto_fills_without_duplicates(): void
    {
        $items = [$this->item('a'), $this->item('b'), $this->item('c'), $this->item('d')];
        $selected = (new HomeHeroMediaSelector())->select(['c', 'a'], $items, 3, 3);
        self::assertSame(['c', 'a', 'b'], array_column($selected, 'title'));
    }

    public function test_invalid_manual_items_are_ignored_and_auto_selection_is_deterministic(): void
    {
        $selected = (new HomeHeroMediaSelector())->select(['missing'], [$this->item('a'), $this->item('b')]);
        self::assertSame(['a', 'b'], array_column($selected, 'title'));
    }

    public function test_duplicate_candidates_are_excluded(): void
    {
        $selected = (new HomeHeroMediaSelector())->select([], [$this->item('a'), $this->item('a'), $this->item('b')]);
        self::assertSame(['a', 'b'], array_column($selected, 'title'));
    }

    public function test_candidates_without_a_real_public_visual_are_skipped_before_selection(): void
    {
        $selected = (new HomeHeroMediaSelector())->select([], [
            $this->item('broken', false),
            $this->item('valid'),
        ], 1, 1);

        self::assertSame(['valid'], array_column($selected, 'title'));
    }

    private function item(string $id, bool $real = true): array
    {
        return [
            '_canonical_id' => $id,
            'title' => $id,
            'image_url' => $real ? '/anh/' . $id . '.webp' : null,
            'has_real_image' => $real,
            'width' => 1200,
            'height' => 800,
        ];
    }
}
