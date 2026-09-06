<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Admin\AdminShell;
use PHPUnit\Framework\TestCase;

final class AdminShellTest extends TestCase
{
    public function test_workspace_without_capability_is_read_only_with_reason(): void
    {
        $definitions = AdminShell::workspaceDefinitions(['manage_options' => true]);

        self::assertSame('read_only', $definitions['governance']['mode']);
        self::assertNotSame('', $definitions['governance']['reason']);
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
}
