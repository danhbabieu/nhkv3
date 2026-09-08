<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class ClaimProjectionMigration016
{
    public const VERSION = 16;

    public static function schemaReady(object $wpdb): bool
    {
        foreach (['nhk_claim_projection_revisions', 'nhk_claim_projection_dependencies'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;
        }
        return true;
    }

    public function up(): void
    {
        global $wpdb;
        $database = (string) $wpdb->get_var('SELECT DATABASE()');
        MigrationDatabaseGuard::assertUpAllowed($database, 'CLAIM_PROJECTION_MIGRATION');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix; $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$p}nhk_claim_projection_revisions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, node_uuid VARCHAR(191) NOT NULL, projection_revision INT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL, input_hash CHAR(64) NOT NULL, claim_set_hash CHAR(64) NOT NULL, graph_hash CHAR(64) NOT NULL, policy_revision INT UNSIGNED NOT NULL DEFAULT 1, template_revision INT UNSIGNED NOT NULL DEFAULT 1, payload_json LONGTEXT NOT NULL, dirty_sections_json LONGTEXT NOT NULL, generated_at DATETIME(6) NOT NULL, published_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY projection_node_revision (node_uuid,projection_revision), KEY projection_node_status (node_uuid,status,projection_revision), KEY projection_input_hash (node_uuid,input_hash)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_claim_projection_dependencies (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, dependency_kind VARCHAR(32) NOT NULL, dependency_id VARCHAR(191) NOT NULL, node_uuid VARCHAR(191) NOT NULL, node_type VARCHAR(64) NULL, section_key VARCHAR(64) NOT NULL, scope VARCHAR(16) NOT NULL, graph_distance TINYINT UNSIGNED NOT NULL DEFAULT 0, dependency_revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY projection_dependency_unique (dependency_kind,dependency_id,node_uuid,section_key), KEY projection_dependency_lookup (dependency_kind,dependency_id), KEY projection_dependency_node (node_uuid,section_key)) {$c}");
        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }

    public function down(bool $force = false): void
    {
        global $wpdb;
        if ((string) $wpdb->get_var('SELECT DATABASE()') !== 'nhk_v3_test' || !$force) throw new \RuntimeException('CLAIM_PROJECTION_MIGRATION_DOWN_REQUIRES_TEST_FORCE');
        foreach (['nhk_claim_projection_dependencies', 'nhk_claim_projection_revisions'] as $suffix) $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $suffix);
    }
}
