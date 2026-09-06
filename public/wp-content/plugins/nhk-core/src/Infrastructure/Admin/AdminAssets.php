<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

/** Loads presentation assets only for NHK V3 Admin screens. */
final class AdminAssets
{
    private static string $pluginFile = '';

    public static function register(string $pluginFile): void
    {
        self::$pluginFile = $pluginFile;
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enqueue(string $hookSuffix): void
    {
        if (!self::isNhkScreen($hookSuffix) || self::$pluginFile === '') return;

        $version = defined('NHK_CORE_VERSION') ? (string) NHK_CORE_VERSION : '0.1.0';
        wp_enqueue_style(
            'nhk-v3-admin-workbench',
            plugins_url('assets/admin/admin-workbench.css', self::$pluginFile),
            [],
            $version
        );
        wp_enqueue_script(
            'nhk-v3-admin-workbench',
            plugins_url('assets/admin/admin-workbench.js', self::$pluginFile),
            [],
            $version,
            true
        );
    }

    private static function isNhkScreen(string $hookSuffix): bool
    {
        if (str_contains($hookSuffix, 'nhk-v3')) return true;
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        return $page === 'nhk-v3' || str_starts_with($page, 'nhk-v3-');
    }
}
