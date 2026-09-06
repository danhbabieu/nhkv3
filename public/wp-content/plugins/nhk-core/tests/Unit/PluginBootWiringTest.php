<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PluginBootWiringTest extends TestCase
{
    public function test_public_entity_projection_dependencies_are_created_before_projection(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');

        $projection = strpos($plugin, 'new EntityMediaProjection($publicMedia, $publicAssets, $publicUsages)');
        $assets = strpos($plugin, '$publicAssets = new WpdbMediaAssetRepository($wpdb);');
        $usages = strpos($plugin, '$publicUsages = new WpdbMediaUsageRepository($wpdb);');

        self::assertNotFalse($projection);
        self::assertNotFalse($assets);
        self::assertNotFalse($usages);
        self::assertLessThan($projection, $assets);
        self::assertLessThan($projection, $usages);
    }

    public function test_plugin_entrypoint_boots_dedicated_entity_dossier_projection(): void
    {
        $entrypoint = (string) file_get_contents(__DIR__ . '/../../nhk-core.php');

        self::assertStringContainsString('use NHK\\Core\\Infrastructure\\Frontend\\EntityDossierBootstrap;', $entrypoint);
        self::assertStringContainsString('EntityDossierBootstrap::boot();', $entrypoint);
    }

    public function test_boot_does_not_run_migrations_without_explicit_runtime_gate(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');

        self::assertStringContainsString(
            "if (self::runtimeMigrationsEnabled()) self::runPendingMigrations();",
            $plugin
        );
        self::assertStringContainsString(
            "defined('NHK_RUN_MIGRATIONS') && NHK_RUN_MIGRATIONS === true",
            $plugin
        );
        self::assertStringContainsString(
            'self::runPendingMigrations();',
            substr($plugin, strpos($plugin, 'public static function activate(): void'))
        );
    }
}
