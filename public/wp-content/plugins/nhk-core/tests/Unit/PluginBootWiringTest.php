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

    public function test_public_entity_detail_runtime_wires_semantic_dossier_through_existing_detail_projection_hook(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');

        $knowledge = strpos($plugin, 'new EntityKnowledgeProjection(');
        $relations = strpos($plugin, 'new RelatedSemanticQuery(');
        $dossier = strpos($plugin, 'new SemanticDossierQuery(');
        $hook = strpos($plugin, "add_filter('nhk_v3_entity_detail_projection'");

        self::assertNotFalse($knowledge);
        self::assertNotFalse($relations);
        self::assertNotFalse($dossier);
        self::assertNotFalse($hook);
        self::assertLessThan($dossier, $knowledge);
        self::assertLessThan($dossier, $relations);
        self::assertLessThan($hook, $dossier);
        self::assertStringContainsString('$value[\'dossier\'] = $publicDossier->forEntity($entity);', $plugin);
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
