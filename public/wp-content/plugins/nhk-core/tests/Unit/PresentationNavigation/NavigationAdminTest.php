<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\PresentationNavigation;

use PHPUnit\Framework\TestCase;

final class NavigationAdminTest extends TestCase
{
    public function test_admin_surface_is_a_tree_with_revision_and_placement_controls(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/ClockTypeNavigationAdminPage.php');
        foreach (['nhk-navigation-tree', 'draggable', 'parent_id', 'sort_order', 'revision', 'show_in_type_index', 'show_in_header_menu', 'show_in_mobile_menu', 'show_in_sidebar', 'featured'] as $marker) self::assertStringContainsString($marker, $source);
        self::assertStringNotContainsString('nhk_entities', $source);
    }

    public function test_menu_registration_is_deferred_until_admin_menu(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/ClockTypeNavigationAdminPage.php');

        self::assertStringContainsString("add_action('admin_menu'", $source);
        self::assertMatchesRegularExpression("/add_action\\('admin_menu'.*\\[self::class, 'registerMenu'\\]/s", $source);
        self::assertStringContainsString("add_submenu_page('nhk-v3'", $source);
    }
}
