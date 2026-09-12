<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use NHK\Core\Infrastructure\Admin\{AdminDetailShell, AdminListTable, AdminReadBackPanel, AdminStatusBadge, AdminTechnicalDetails};
use PHPUnit\Framework\TestCase;

final class AdminSharedPresentationTest extends TestCase
{
    public function test_list_table_renders_empty_state_and_escaped_cells(): void
    {
        ob_start();
        AdminListTable::render('Video', ['title' => 'Tiêu đề'], [['title' => '<Video>']], ['label' => 'Chưa có Video']);
        AdminListTable::render('Media', ['title' => 'Tiêu đề'], [], ['label' => 'Chưa có Media']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Chưa có Media', $html);
        self::assertStringContainsString('&lt;Video&gt;', $html);
        self::assertStringNotContainsString('<Video>', $html);
    }

    public function test_status_readback_and_technical_details_are_separate(): void
    {
        ob_start();
        AdminStatusBadge::render('Canonical', 'applied', 'Đã đọc lại');
        AdminReadBackPanel::render(['canonical' => ['state' => 'applied', 'label' => 'Đã áp dụng'], 'frontend' => ['state' => 'pending', 'label' => 'Đang chờ']]);
        AdminTechnicalDetails::render(['proposal_uuid' => 'secret-id']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Canonical', $html);
        self::assertStringContainsString('Đã áp dụng', $html);
        self::assertStringContainsString('<details', $html);
        self::assertStringContainsString('secret-id', $html);
    }

    public function test_detail_shell_uses_semantic_main_heading(): void
    {
        ob_start();
        AdminDetailShell::render('Chi tiết Video', 'Mô tả', static function (): void { echo '<p>Nội dung</p>'; });
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<h2', $html);
        self::assertStringContainsString('Chi tiết Video', $html);
        self::assertStringContainsString('Nội dung', $html);
    }

    public function test_completion_panel_separates_proposal_from_public_and_frontend_state(): void
    {
        ob_start();
        AdminReadBackPanel::renderCompletion([
            'proposal_state' => 'applied', 'canonical_state' => 'COMPLETE', 'dependency_state' => 'COMPLETE',
            'relation_or_usage_state' => 'PARTIAL', 'public_state' => 'BLOCKED', 'frontend_state' => 'BLOCKED',
            'blockers' => ['MEDIAUSAGE_INCOMPLETE'],
        ]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Proposal', $html);
        self::assertStringContainsString('Đã áp dụng', $html);
        self::assertStringContainsString('Public projection', $html);
        self::assertStringContainsString('Bị chặn', $html);
        self::assertStringContainsString('MEDIAUSAGE_INCOMPLETE', $html);
    }
}
