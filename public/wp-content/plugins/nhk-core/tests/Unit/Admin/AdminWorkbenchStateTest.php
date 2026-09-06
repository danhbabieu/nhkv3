<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use NHK\Core\Infrastructure\Admin\AdminWorkbenchState;
use PHPUnit\Framework\TestCase;

final class AdminWorkbenchStateTest extends TestCase
{
    public function test_state_rows_remain_independent_and_count_blockers(): void
    {
        $state = new AdminWorkbenchState([
            ['label' => 'Governance', 'value' => 'Đã duyệt', 'tone' => 'attention'],
            ['label' => 'Xác minh', 'value' => 'Không khả dụng', 'tone' => 'blocked'],
            ['label' => 'Public route', 'value' => 'Sẵn sàng', 'tone' => 'ready'],
        ]);

        self::assertSame(3, $state->count());
        self::assertSame(1, $state->blockerCount());
        self::assertSame('Đã duyệt', $state->rows()[0]['value']);
        self::assertSame('Không khả dụng', $state->rows()[1]['value']);
        self::assertSame('Sẵn sàng', $state->rows()[2]['value']);
    }

    public function test_unknown_tone_is_normalized_to_neutral_without_changing_the_value(): void
    {
        $state = new AdminWorkbenchState([
            ['label' => 'Runtime', 'value' => 'Chưa kiểm chứng', 'tone' => 'mystery'],
        ]);

        self::assertSame([
            ['label' => 'Runtime', 'value' => 'Chưa kiểm chứng', 'tone' => 'neutral'],
        ], $state->rows());
        self::assertSame(0, $state->blockerCount());
    }

    public function test_blank_rows_are_not_invented_into_success_states(): void
    {
        $state = new AdminWorkbenchState([
            ['label' => '', 'value' => '', 'tone' => 'ready'],
            ['label' => 'Dictionary', 'value' => 'Không khả dụng', 'tone' => 'blocked'],
        ]);

        self::assertSame([
            ['label' => 'Dictionary', 'value' => 'Không khả dụng', 'tone' => 'blocked'],
        ], $state->rows());
        self::assertSame(1, $state->blockerCount());
    }
}
