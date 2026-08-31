<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class KnowledgeEvidenceMetadataMigration007
{
    public const VERSION = 7;

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix; $c = $wpdb->get_charset_collate();
        $table = "{$p}nhk_evidence";
        dbDelta("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, evidence_uuid BINARY(16) NOT NULL, claim_uuid BINARY(16) NOT NULL, source_uuid BINARY(16) NOT NULL, relation_type VARCHAR(16) NOT NULL, excerpt TEXT NOT NULL, locator VARCHAR(2048) NULL, metadata_json LONGTEXT NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY evidence_uuid_unique (evidence_uuid), KEY evidence_claim (claim_uuid,state,id), KEY evidence_source (source_uuid,state,id)) {$c}");
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'metadata_json'))) {
            if ($wpdb->query("ALTER TABLE {$table} ADD COLUMN metadata_json LONGTEXT NULL AFTER locator") === false) throw new \RuntimeException('EVIDENCE_METADATA_COLUMN_ADD_FAILED');
        }
        update_option('nhk_core_migration_current', self::VERSION, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }

    public function down(): void
    {
        global $wpdb;
        if ((string) $wpdb->get_var('SELECT DATABASE()') !== 'nhk_v3_test') throw new \RuntimeException('EVIDENCE_METADATA_MIGRATION_DOWN_REQUIRES_NHK_V3_TEST');
        $wpdb->query("ALTER TABLE {$wpdb->prefix}nhk_evidence DROP COLUMN metadata_json");
        update_option('nhk_core_migration_current', 6, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }
}
