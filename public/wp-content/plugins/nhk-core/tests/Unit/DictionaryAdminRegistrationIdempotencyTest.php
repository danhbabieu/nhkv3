<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin {
    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        if ($hook !== 'admin_menu') return;
        $GLOBALS['nhk_dictionary_test_actions'][$priority][] = $callback;
    }

    function add_submenu_page(string $parent, string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable $callback): void
    {
        $GLOBALS['nhk_dictionary_test_submenus'][] = compact('parent', 'pageTitle', 'menuTitle', 'capability', 'menuSlug', 'callback');
    }

    function run_dictionary_test_admin_menu(): void
    {
        ksort($GLOBALS['nhk_dictionary_test_actions']);
        foreach ($GLOBALS['nhk_dictionary_test_actions'] as $callbacks) {
            foreach ($callbacks as $callback) $callback();
        }
    }
}

namespace NHK\Core\Infrastructure\Dictionary {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {}
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['nhk_dictionary_test_options'][$key] ?? $default;
    }
    function update_option(string $key, mixed $value, bool $autoload = true): bool
    {
        $GLOBALS['nhk_dictionary_test_options'][$key] = $value;
        $GLOBALS['nhk_dictionary_test_option_updates'][$key] = ($GLOBALS['nhk_dictionary_test_option_updates'][$key] ?? 0) + 1;
        return true;
    }
}

namespace NHK\Core\Application\Governance {
    function get_role(string $role): ?object { return null; }
}

namespace NHK\Tests\Unit {

use NHK\Core\Application\Dictionary\DictionaryRuntime;
use NHK\Core\Infrastructure\Dictionary\DictionaryBootstrap;
use NHK\Core\Infrastructure\Admin\{DictionaryAdminPage, DictionaryBackfillAdminPage};
use PHPUnit\Framework\TestCase;

final class DictionaryAdminRegistrationIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['nhk_dictionary_test_actions'] = [];
        $GLOBALS['nhk_dictionary_test_submenus'] = [];
        $GLOBALS['nhk_dictionary_test_options'] = [];
        $GLOBALS['nhk_dictionary_test_option_updates'] = [];
    }

    public function test_repeated_registration_produces_one_dictionary_submenu_each_and_preserves_other_menus(): void
    {
        /** @var DictionaryRuntime $runtime */
        $runtime = (new \ReflectionClass(DictionaryRuntime::class))->newInstanceWithoutConstructor();
        \NHK\Core\Infrastructure\Admin\add_action('admin_menu', static function (): void {
            \NHK\Core\Infrastructure\Admin\add_submenu_page('nhk-v3', 'Nội dung', 'Nội dung', 'edit_posts', 'nhk-v3-content', static function (): void {});
        }, 10);

        DictionaryAdminPage::register($runtime);
        DictionaryAdminPage::register($runtime);
        DictionaryBackfillAdminPage::register($runtime);
        DictionaryBackfillAdminPage::register($runtime);

        \NHK\Core\Infrastructure\Admin\run_dictionary_test_admin_menu();

        $slugs = array_column($GLOBALS['nhk_dictionary_test_submenus'], 'menuSlug');
        self::assertSame(['nhk-v3-content', 'nhk-v3-dictionary', 'nhk-v3-dictionary-backfill'], $slugs);
        self::assertSame(1, count(array_filter($slugs, static fn (string $slug): bool => $slug === 'nhk-v3-dictionary')));
        self::assertSame(1, count(array_filter($slugs, static fn (string $slug): bool => $slug === 'nhk-v3-dictionary-backfill')));
    }

    public function test_repeated_boot_is_idempotent_and_entrypoint_has_one_boot_owner(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $query, string $value): string { return $query . $value; }
            public function get_var(string $query): string { return ''; }
        };
        $GLOBALS['wpdb'] = $wpdb;

        DictionaryBootstrap::boot();
        $runtime = DictionaryBootstrap::runtime();
        DictionaryBootstrap::boot();

        self::assertSame($runtime, DictionaryBootstrap::runtime());
        self::assertSame(0, $GLOBALS['nhk_dictionary_test_option_updates']['nhk_core_migration_target'] ?? 0);

        $entry = (string) file_get_contents(dirname(__DIR__, 2) . '/nhk-core.php');
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
        self::assertSame(0, substr_count($entry, 'DictionaryBootstrap::boot();'));
        self::assertSame(1, substr_count($plugin, 'DictionaryBootstrap::boot();'));
    }
}
}
