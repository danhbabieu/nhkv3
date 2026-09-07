<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class GovernanceAutomationAdminTest extends TestCase
{
    public function test_system_admin_exposes_policy_page_and_all_three_modes(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/AdminWorkbenchPage.php');

        self::assertStringContainsString('renderAutomationPolicy', $source);
        self::assertStringContainsString('admin_post_nhk_governance_automation_policy', $source);
        self::assertStringContainsString('Cần phê duyệt', $source);
        self::assertStringContainsString('Tự động phê duyệt', $source);
        self::assertStringContainsString('Tự động phê duyệt & xuất bản', $source);
        self::assertStringContainsString('check_admin_referer', $source);
        self::assertStringContainsString('AUTO_PUBLISH', $source);
    }
}
