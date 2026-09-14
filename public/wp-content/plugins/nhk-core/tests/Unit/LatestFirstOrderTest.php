<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Presentation\LatestFirstOrder;
use PHPUnit\Framework\TestCase;

final class LatestFirstOrderTest extends TestCase
{
    public function test_default_feed_orders_published_then_created_then_stable_tie_breaker(): void
    {
        $items = [
            ['id' => 'a', 'published_at' => '2026-09-10T00:00:00Z', 'created_at' => '2026-09-01T00:00:00Z'],
            ['id' => 'c', 'published_at' => '2026-09-11T00:00:00Z', 'created_at' => '2026-09-01T00:00:00Z'],
            ['id' => 'b', 'published_at' => '2026-09-10T00:00:00Z', 'created_at' => '2026-09-02T00:00:00Z'],
            ['id' => 'd', 'published_at' => '2026-09-10T00:00:00Z', 'created_at' => '2026-09-01T00:00:00Z'],
        ];

        $ordered = LatestFirstOrder::sort(
            $items,
            static fn (array $item): ?string => $item['published_at'],
            static fn (array $item): ?string => $item['created_at'],
            static fn (array $item): string => $item['id'],
        );

        self::assertSame(['c', 'b', 'd', 'a'], array_column($ordered, 'id'));
    }

    public function test_updated_mode_orders_moi_cap_nhat_and_paginates_after_ordering(): void
    {
        $items = [
            ['id' => 'a', 'updated_at' => '2026-09-12T00:00:00Z', 'created_at' => '2026-09-01T00:00:00Z'],
            ['id' => 'c', 'updated_at' => '2026-09-14T00:00:00Z', 'created_at' => '2026-09-01T00:00:00Z'],
            ['id' => 'b', 'updated_at' => '2026-09-12T00:00:00Z', 'created_at' => '2026-09-02T00:00:00Z'],
            ['id' => 'd', 'updated_at' => '2026-09-12T00:00:00Z', 'created_at' => '2026-09-01T00:00:00Z'],
        ];

        $ordered = LatestFirstOrder::sort(
            $items,
            static fn (array $item): ?string => null,
            static fn (array $item): ?string => $item['created_at'],
            static fn (array $item): string => $item['id'],
            null,
            static fn (array $item): ?string => $item['updated_at'],
            'updated',
        );

        self::assertSame(['c', 'b', 'd', 'a'], array_column($ordered, 'id'));
        self::assertSame(['b', 'd'], array_column(array_slice($ordered, 1, 2), 'id'));
    }
}
