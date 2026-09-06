<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Admin\AdminWorkspaceViewModel;
use PHPUnit\Framework\TestCase;

final class AdminWorkspaceViewModelTest extends TestCase
{
    public function test_unavailable_health_is_not_rendered_as_zero(): void
    {
        $view = AdminWorkspaceViewModel::fromHealth(['database' => null], [], []);

        self::assertSame('unavailable', $view['health']['database']['state']);
        self::assertNull($view['health']['database']['value']);
        self::assertSame('Không khả dụng', $view['health']['database']['state_label']);
        self::assertSame('Không khả dụng', $view['health']['database']['display']);
    }

    public function test_failed_health_layer_remains_blocked_with_its_diagnostic(): void
    {
        $view = AdminWorkspaceViewModel::fromHealth([
            'runtime' => ['ok' => false, 'reason_code' => 'COMPOSER_AUTOLOAD_MISSING'],
        ], [], []);

        self::assertSame('blocked', $view['health']['runtime']['state']);
        self::assertSame('COMPOSER_AUTOLOAD_MISSING', $view['health']['runtime']['reason_code']);
        self::assertSame('system_blocked', $view['health']['runtime']['diagnostic']['severity']);
    }

    public function test_runtime_exception_is_blocked_without_becoming_a_success_value(): void
    {
        $view = AdminWorkspaceViewModel::fromHealth([
            'runtime' => new \RuntimeException('sensitive infrastructure detail'),
        ], [], []);

        self::assertSame('blocked', $view['health']['runtime']['state']);
        self::assertNull($view['health']['runtime']['value']);
        self::assertSame('Lỗi runtime', $view['health']['runtime']['display']);
    }

    public function test_capabilities_and_counts_keep_truthful_presentation_states(): void
    {
        $view = AdminWorkspaceViewModel::fromHealth(
            [],
            ['nhk_view_governance' => true, 'nhk_apply_proposals' => false],
            ['pending_review' => 0, 'recent_failures' => 2, 'unknown_total' => null],
        );

        self::assertSame('ready', $view['capabilities']['nhk_view_governance']['state']);
        self::assertSame('blocked', $view['capabilities']['nhk_apply_proposals']['state']);
        self::assertSame('empty', $view['counts']['pending_review']['state']);
        self::assertSame('success', $view['counts']['recent_failures']['state']);
        self::assertSame('unavailable', $view['counts']['unknown_total']['state']);
    }
}
