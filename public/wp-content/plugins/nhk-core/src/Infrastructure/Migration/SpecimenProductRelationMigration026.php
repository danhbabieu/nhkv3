<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

/**
 * Forward-only registry-data preparation for the specimen catalogue.
 *
 * This migration is wired into the official forward-only UP runner. It only
 * extends the Graph predicate dictionary; semantic entities and edges remain
 * untouched.
 */
final class SpecimenProductRelationMigration026
{
    public const VERSION = 26;

    public function up(): void
    {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'SPECIMEN_PRODUCT_RELATION_REGISTRY');
        $table = $wpdb->prefix . 'nhk_graph_predicates';
        foreach (['specimen_of', 'lists_specimen'] as $predicate) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (predicate_key,created_at) VALUES (%s,%s)",
                $predicate,
                gmdate('Y-m-d H:i:s.u'),
            ));
        }
        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }

    public static function schemaReady(object $database): bool
    {
        $table = $database->prefix . 'nhk_graph_predicates';
        foreach (['specimen_of', 'lists_specimen'] as $predicate) {
            if (!(bool) $database->get_var($database->prepare("SELECT id FROM {$table} WHERE predicate_key=%s LIMIT 1", $predicate))) return false;
        }
        return true;
    }
}
