<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

/**
 * Forward-only registry-data preparation for the specimen catalogue.
 *
 * This migration is intentionally not wired into Plugin::runPendingMigrations
 * in the local implementation checkpoint. Deployment/acceptance must opt into
 * it explicitly after the release gate and registry snapshot are verified.
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
