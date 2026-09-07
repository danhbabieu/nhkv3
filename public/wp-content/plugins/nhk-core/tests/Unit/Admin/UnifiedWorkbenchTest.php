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

    public function test_media_workbench_exposes_shared_image_and_video_workspaces_with_publication_tabs(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/AdminWorkbenchPage.php');
        foreach (['Tất cả', 'Hình ảnh', 'Video', 'Tổng quan', 'Nội dung & SEO', 'Vai trò & Quan hệ', 'Tri thức', 'Nguồn & Evidence', 'Sử dụng', 'Governance', 'Kỹ thuật'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        self::assertStringContainsString('Xem trên web', $source . (string) file_get_contents(dirname(__DIR__, 3) . '/assets/admin/admin-workbench.js'));
        self::assertStringContainsString('Mở nguồn gốc', $source . (string) file_get_contents(dirname(__DIR__, 3) . '/assets/admin/admin-workbench.js'));
    }

    public function test_normal_media_video_workflow_keeps_technical_identifiers_out_of_primary_forms(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/AdminWorkbenchPage.php');
        foreach (['proposal UUID', 'Evidence UUID', 'fingerprint', 'expected revision', 'raw JSON'] as $technicalLabel) {
            self::assertStringNotContainsString('name="' . $technicalLabel . '"', $source);
        }
        self::assertStringContainsString('Chi tiết kỹ thuật', $source . (string) file_get_contents(dirname(__DIR__, 3) . '/assets/admin/admin-workbench.js'));
    }
}
