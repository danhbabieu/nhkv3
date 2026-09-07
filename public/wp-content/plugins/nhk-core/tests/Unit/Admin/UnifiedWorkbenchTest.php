<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class UnifiedWorkbenchTest extends TestCase
{
    public function test_primary_menu_registers_all_user_facing_workspaces_and_keeps_raw_tools_advanced(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/AdminWorkbenchPage.php');
        foreach (['Nội dung', 'Media', 'Tri thức', 'Duyệt', 'Hệ thống', 'Nâng cao', 'renderContent', 'renderMedia', 'renderKnowledge', 'renderGovernance'] as $needle) self::assertStringContainsString($needle, $source);
        self::assertStringContainsString("[AdminPage::class, 'render']", $source);
        self::assertStringContainsString('Chi tiết kỹ thuật', $source . (string) file_get_contents(dirname(__DIR__, 3) . '/assets/admin/admin-workbench.js'));
    }

    public function test_main_workbench_does_not_require_proposal_or_evidence_uuid_for_search(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/AdminWorkbenchPage.php');
        self::assertStringContainsString('Tiêu đề, YouTube ID hoặc canonical UUID', $source);
        self::assertStringNotContainsString('nhk-video-proposal-id', $source);
        self::assertStringNotContainsString('nhk-video-evidence-id', $source);
    }
}
