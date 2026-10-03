<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class DictionaryEntrySenseMigration024
{
    public const VERSION = 24;

    public static function schemaReady(object $wpdb): bool
    {
        foreach (['nhk_dictionary_entries', 'nhk_dictionary_forms', 'nhk_dictionary_entry_senses'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;
        }
        return true;
    }

    public function up(): void
    {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'DICTIONARY_ENTRY_SENSE_MIGRATION');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$p}nhk_dictionary_entries (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, entry_uuid BINARY(16) NOT NULL, preferred_form VARCHAR(255) NOT NULL, normalized_preferred_form VARCHAR(191) NOT NULL, status VARCHAR(16) NOT NULL, locale VARCHAR(32) NULL, context_json LONGTEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY dictionary_entry_uuid (entry_uuid), KEY dictionary_entry_form (normalized_preferred_form,status,id)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_dictionary_forms (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, entry_uuid BINARY(16) NOT NULL, form_text VARCHAR(255) NOT NULL, normalized_form VARCHAR(191) NOT NULL, form_kind VARCHAR(32) NOT NULL, locale VARCHAR(32) NULL, context_hash CHAR(64) NOT NULL, context_json LONGTEXT NOT NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY dictionary_entry_form_unique (entry_uuid,normalized_form,context_hash), KEY dictionary_entry_form_lookup (normalized_form,state,context_hash)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_dictionary_entry_senses (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, entry_uuid BINARY(16) NOT NULL, concept_uuid BINARY(16) NOT NULL, sense_context_hash CHAR(64) NOT NULL, semantic_reference_type VARCHAR(64) NULL, semantic_reference_id VARCHAR(191) NULL, semantic_reference_revision INT UNSIGNED NULL, context_json LONGTEXT NOT NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY dictionary_entry_sense_unique (entry_uuid,concept_uuid), KEY dictionary_sense_entry (entry_uuid,state,id), KEY dictionary_sense_concept (concept_uuid,state,id)) {$c}");
        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }
}
