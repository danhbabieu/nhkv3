<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class KnowledgeMigration005
{
    public const VERSION = 5;

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix; $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$p}nhk_knowledge_claims (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, canonical_uuid BINARY(16) NOT NULL, stable_key VARCHAR(191) NOT NULL, claim_text TEXT NOT NULL, claim_type VARCHAR(32) NOT NULL, provenance_json LONGTEXT NOT NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY claim_uuid_unique (canonical_uuid), UNIQUE KEY claim_key_unique (stable_key), KEY claim_state_id (state,id), KEY claim_type_state (claim_type,state,id)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_sources (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, canonical_uuid BINARY(16) NOT NULL, stable_key VARCHAR(191) NOT NULL, title VARCHAR(255) NOT NULL, source_type VARCHAR(32) NOT NULL, locator VARCHAR(2048) NULL, metadata_json LONGTEXT NOT NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY source_uuid_unique (canonical_uuid), UNIQUE KEY source_key_unique (stable_key), KEY source_state_id (state,id), KEY source_type_state (source_type,state,id)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_evidence (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, evidence_uuid BINARY(16) NOT NULL, claim_uuid BINARY(16) NOT NULL, source_uuid BINARY(16) NOT NULL, relation_type VARCHAR(16) NOT NULL, excerpt TEXT NOT NULL, locator VARCHAR(2048) NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY evidence_uuid_unique (evidence_uuid), KEY evidence_claim (claim_uuid,state,id), KEY evidence_source (source_uuid,state,id)) {$c}");
        update_option('nhk_core_migration_current', self::VERSION, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }

    public function down(bool $force = false): void
    {
        global $wpdb;
        if ((string) $wpdb->get_var('SELECT DATABASE()') !== 'nhk_v3_test') throw new \RuntimeException('KNOWLEDGE_MIGRATION_DOWN_REQUIRES_NHK_V3_TEST');
        $p = $wpdb->prefix;
        foreach (['nhk_evidence', 'nhk_sources', 'nhk_knowledge_claims'] as $table) $wpdb->query("DROP TABLE IF EXISTS {$p}{$table}");
        update_option('nhk_core_migration_current', 4, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }
}
