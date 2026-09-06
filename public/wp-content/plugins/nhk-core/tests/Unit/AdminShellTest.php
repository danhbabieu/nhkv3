<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Admin\AdminShell;
use PHPUnit\Framework\TestCase;

final class AdminShellTest extends TestCase
{
    public function test_workspace_without_capability_is_read_only_with_reason(): void
    {
        $definitions = AdminShell::workspaceDefinitions(['nhk_view_governance' => true]);

        self::assertSame('read_only', $definitions['governance']['mode']);
        self::assertNotSame('', $definitions['governance']['reason']);
    }

    public function test_governance_uses_the_same_granular_capabilities_as_mcp(): void
    {
        $definitions = AdminShell::workspaceDefinitions([
            'nhk_view_governance' => true,
            'nhk_create_proposals' => true,
            'nhk_submit_proposals' => false,
            'nhk_approve_proposals' => true,
            'nhk_apply_proposals' => false,
        ]);

        self::assertSame('nhk_view_governance', $definitions['governance']['read_capability']);
        self::assertSame([
            'nhk_create_proposals',
            'nhk_submit_proposals',
            'nhk_approve_proposals',
            'nhk_apply_proposals',
        ], $definitions['governance']['write_capability']);
        self::assertSame('limited', $definitions['governance']['mode']);
        self::assertTrue($definitions['governance']['actions']['create']['allowed']);
        self::assertFalse($definitions['governance']['actions']['submit']['allowed']);
        self::assertTrue($definitions['governance']['actions']['approve']['allowed']);
        self::assertFalse($definitions['governance']['actions']['apply']['allowed']);
        self::assertStringContainsString('nhk_submit_proposals', $definitions['governance']['reason']);
        self::assertStringContainsString('nhk_apply_proposals', $definitions['governance']['reason']);
    }

    public function test_governance_requires_view_capability_even_when_apply_is_granted(): void
    {
        $definitions = AdminShell::workspaceDefinitions([
            'manage_options' => true,
            'nhk_apply_proposals' => true,
        ]);

        self::assertFalse($definitions['governance']['available']);
        self::assertSame('unavailable', $definitions['governance']['mode']);
        self::assertStringContainsString('nhk_view_governance', $definitions['governance']['reason']);
    }

    public function test_full_governance_mode_requires_every_governance_write_capability(): void
    {
        $definitions = AdminShell::workspaceDefinitions([
            'nhk_view_governance' => true,
            'nhk_create_proposals' => true,
            'nhk_submit_proposals' => true,
            'nhk_approve_proposals' => true,
            'nhk_apply_proposals' => true,
        ]);

        self::assertSame('full', $definitions['governance']['mode']);
        self::assertSame('', $definitions['governance']['reason']);
    }

    public function test_approved_workspace_set_is_represented_in_order(): void
    {
        $definitions = AdminShell::workspaceDefinitions([]);

        self::assertSame(
            ['overview', 'governance', 'editorial', 'semantic', 'media-video', 'operations'],
            array_keys($definitions)
        );
        self::assertSame(
            ['Tổng quan', 'Governance', 'Biên tập', 'Semantic', 'Media & Video', 'Vận hành'],
            array_column($definitions, 'label')
        );
        self::assertSame('unavailable', $definitions['editorial']['mode']);
        self::assertNotSame('', $definitions['editorial']['reason']);
    }

    public function test_shell_contains_skip_link_navigation_and_main_landmarks(): void
    {
        ob_start();
        AdminShell::render(
            'governance',
            ['governance' => ['label' => 'Governance', 'mode' => 'read_only']],
            static function (): void {
                echo '<p>Nội dung</p>';
            }
        );
        $html = (string) ob_get_clean();

        self::assertStringContainsString('href="#nhk-admin-main"', $html);
        self::assertStringContainsString('<nav', $html);
        self::assertStringContainsString('<main id="nhk-admin-main"', $html);
    }

    public function test_every_navigation_fragment_has_a_rendered_workspace_region(): void
    {
        $definitions = AdminShell::workspaceDefinitions([
            'manage_options' => true,
            'nhk_view_governance' => true,
            'nhk_create_proposals' => true,
        ]);

        ob_start();
        AdminShell::render('governance', $definitions, static function () use ($definitions): void {
            foreach ($definitions as $definition) {
                AdminShell::renderWorkspaceRegion(
                    (string) $definition['slug'],
                    (string) $definition['label'],
                    static function (): void { echo '<p>Nội dung hoặc trạng thái khả dụng.</p>'; }
                );
            }
        });
        $html = (string) ob_get_clean();

        preg_match_all('/href="#(nhk-workspace-[^"]+)"/', $html, $matches);
        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $target) {
            self::assertStringContainsString('id="' . $target . '"', $html);
        }
    }

    public function test_read_only_message_keeps_permitted_read_control(): void
    {
        $definitions = AdminShell::workspaceDefinitions(['nhk_view_governance' => true]);

        ob_start();
        AdminShell::render('governance', $definitions, static function (): void {
            echo '<button type="button">Eligibility</button>';
        });
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Chế độ chỉ đọc', $html);
        self::assertStringContainsString('nhk_create_proposals', $html);
        self::assertStringContainsString('>Eligibility</button>', $html);
    }

    public function test_asset_scope_accepts_only_nhk_admin_screens(): void
    {
        self::assertTrue(AdminShell::isNhkScreen('toplevel_page_nhk-v3', ''));
        self::assertTrue(AdminShell::isNhkScreen('admin_page_other', 'nhk-v3-advanced'));
        self::assertFalse(AdminShell::isNhkScreen('dashboard_page_home', ''));
        self::assertFalse(AdminShell::isNhkScreen('settings_page_other', 'other-plugin'));
    }
}
