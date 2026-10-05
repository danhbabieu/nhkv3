<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Migration;
final class GraphRelationContextMigration002
{
    public const VERSION = 2;
    public static function schemaReady(object $wpdb): bool
    {
        $table = $wpdb->prefix . 'nhk_graph_relation_context';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_graph_predicates WHERE predicate_key=%s LIMIT 1", 'associated_with'));
    }
    public function up(): void
    {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'GRAPH_RELATION_CONTEXT_MIGRATION');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix; $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$p}nhk_graph_relation_context (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, context_uuid BINARY(16) NOT NULL, edge_uuid BINARY(16) NOT NULL, source_revision INT UNSIGNED NOT NULL, target_revision INT UNSIGNED NOT NULL, scope_code VARCHAR(64) NOT NULL, scope_subject_type VARCHAR(64) NOT NULL, scope_subject_id VARCHAR(191) NOT NULL, provenance_class VARCHAR(64) NOT NULL, evidence_refs_json LONGTEXT NOT NULL, approval_fingerprint CHAR(64) NOT NULL, idempotency_key VARCHAR(191) NOT NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, retired_at DATETIME(6) NULL, PRIMARY KEY (id), UNIQUE KEY context_uuid_unique (context_uuid), UNIQUE KEY edge_uuid_unique (edge_uuid), UNIQUE KEY idempotency_key_unique (idempotency_key), KEY edge_state (edge_uuid,state), KEY scope_lookup (scope_subject_type,scope_subject_id,state,id), KEY provenance_lookup (provenance_class,state,id)) {$c}");
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$p}nhk_graph_predicates (predicate_key,created_at) VALUES (%s,%s)", 'associated_with', gmdate('Y-m-d H:i:s.u')));
        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }
}
