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

    public function test_runner_verifies_dictionary_entry_sense_schema_after_pending_migrations(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
        $entrypoint = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/nhk-core-maintenance.php');
        self::assertStringContainsString("if (!DictionaryEntrySenseMigration024::schemaReady(\$wpdb)) throw new \\RuntimeException('MIGRATION_SCHEMA_NOT_READY');", $plugin);
        self::assertStringContainsString('dictionary_entry_sense_schema_ready', $entrypoint);
    }

    public function test_migration_receipt_uses_canonical_current_target_and_machine_readable_schema_failure(): void
    {
        $entrypoint = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/nhk-core-maintenance.php');
        self::assertStringContainsString("SpecimenProductRelationMigration026::VERSION", $entrypoint);
        self::assertStringNotContainsString("\$current !== 24 || \$target !== 24", $entrypoint);
        self::assertStringContainsString("'DICTIONARY_ENTRY_SENSE_SCHEMA_NOT_READY'", $entrypoint);
        self::assertStringContainsString("'dictionary_entry_sense_schema_ready' => \$schemaReady", $entrypoint);
        self::assertStringContainsString("'specimen_product_relation_schema_ready' => \$specimenProductRelationSchemaReady", $entrypoint);
    }
}
