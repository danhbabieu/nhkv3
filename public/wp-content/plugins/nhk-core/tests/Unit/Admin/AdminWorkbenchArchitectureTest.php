<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class AdminWorkbenchArchitectureTest extends TestCase
{
    public function test_workbench_presentation_is_split_into_small_dedicated_files(): void
    {
        foreach ($this->productionFiles() as $path) {
            self::assertFileExists($path, 'Missing Admin presentation file: ' . $path);
            self::assertGreaterThan(0, filesize($path));
        }

        self::assertFileExists($this->repo() . '/public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.css');
        self::assertFileExists($this->repo() . '/public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.js');
    }

    public function test_new_admin_presentation_files_do_not_add_semantic_database_writers(): void
    {
        foreach ($this->productionFiles() as $path) {
            if (!is_file($path)) continue;
            $source = (string) file_get_contents($path);
            self::assertStringNotContainsString('$wpdb->query', $source, $path);
            self::assertDoesNotMatchRegularExpression('/\bINSERT\s+INTO\b/i', $source, $path);
            self::assertDoesNotMatchRegularExpression('/\bUPDATE\s+[^\n]+\s+SET\b/i', $source, $path);
            self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b/i', $source, $path);
            self::assertStringNotContainsString('->save(', $source, $path);
            self::assertStringNotContainsString('->create(', $source, $path);
        }
    }

    public function test_workbench_uses_external_assets_instead_of_new_inline_presentation_blobs(): void
    {
        foreach ([
            $this->repo() . '/public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchPage.php',
            $this->repo() . '/public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminAssets.php',
        ] as $path) {
            if (!is_file($path)) continue;
            $source = (string) file_get_contents($path);
            self::assertStringNotContainsString('<style', strtolower($source));
            self::assertStringNotContainsString('<script', strtolower($source));
        }
    }

    public function test_plugin_wires_workbench_as_primary_admin_entrypoint(): void
    {
        $plugin = (string) file_get_contents($this->repo() . '/public/wp-content/plugins/nhk-core/src/Plugin.php');

        self::assertStringContainsString('use NHK\\Core\\Infrastructure\\Admin\\AdminWorkbenchPage;', $plugin);
        self::assertStringContainsString('use NHK\\Core\\Infrastructure\\Admin\\AdminAssets;', $plugin);
        self::assertStringContainsString('AdminWorkbenchPage::register', $plugin);
        self::assertStringContainsString('AdminAssets::register', $plugin);
        self::assertStringNotContainsString("add_action('admin_menu', [AdminPage::class, 'register']);", $plugin);
    }

    /** @return list<string> */
    private function productionFiles(): array
    {
        $base = $this->repo() . '/public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/';
        return [
            $base . 'AdminWorkbenchRegistry.php',
            $base . 'AdminWorkbenchState.php',
            $base . 'AdminAssets.php',
            $base . 'AdminWorkbenchPage.php',
        ];
    }

    private function repo(): string
    {
        return dirname(__DIR__, 7);
    }
}
