<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MaintenanceMigrationRunnerTest extends TestCase
{
    public function test_maintenance_migration_up_delegates_to_the_canonical_plugin_runner(): void
    {
        $entrypoint = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/nhk-core-maintenance.php');

        self::assertStringContainsString('use NHK\\Core\\Plugin;', $entrypoint);
        self::assertStringContainsString('Plugin::runPendingMigrations();', $entrypoint);
        self::assertStringNotContainsString('PublicIdentityMigration014', $entrypoint);
        self::assertStringNotContainsString('DictionaryMigration015', $entrypoint);
    }

    public function test_plugin_runner_is_publicly_callable_by_the_maintenance_boundary(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');

        self::assertStringContainsString('public static function runPendingMigrations(): void', $plugin);
    }

    public function test_shared_runner_checks_the_existing_database_runtime_guard_before_any_step(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');

        self::assertStringContainsString("MigrationDatabaseGuard::assertUpAllowed((string) \$wpdb->get_var('SELECT DATABASE()'), 'PENDING_MIGRATIONS');", $plugin);
    }
}
