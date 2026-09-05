<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class DictionaryMigration015
{
    public const VERSION = 15;

    public static function schemaReady(object $wpdb): bool
    {
        foreach (['nhk_dictionary_concepts', 'nhk_dictionary_labels', 'nhk_dictionary_candidates', 'nhk_dictionary_mentions'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;
        }
        return true;
    }

    public function up(): void
    {
        global $wpdb;
        $database = (string) $wpdb->get_var('SELECT DATABASE()');
        if (!in_array($database, ['nhk_v3', 'nhk_v3_test'], true)) throw new \RuntimeException('DICTIONARY_MIGRATION_UP_REQUIRES_NHK_V3_OR_TEST');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}nhk_dictionary_concepts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, concept_uuid BINARY(16) NOT NULL, preferred_label VARCHAR(255) NOT NULL, definition_text TEXT NOT NULL, status VARCHAR(16) NOT NULL, destination_type VARCHAR(64) NULL, destination_id VARCHAR(191) NULL, destination_url VARCHAR(2048) NULL, context_json LONGTEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY dictionary_concept_uuid (concept_uuid), KEY dictionary_concept_status (status,id)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_dictionary_labels (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, concept_uuid BINARY(16) NOT NULL, label_text VARCHAR(255) NOT NULL, normalized_label VARCHAR(191) NOT NULL, label_kind VARCHAR(32) NOT NULL, locale VARCHAR(32) NULL, context_hash CHAR(64) NOT NULL, context_json LONGTEXT NOT NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY dictionary_label_concept (concept_uuid,normalized_label,context_hash), KEY dictionary_label_lookup (normalized_label,state,context_hash), KEY dictionary_label_concept_state (concept_uuid,state,id)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_dictionary_candidates (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, candidate_uuid BINARY(16) NOT NULL, normalized_term VARCHAR(191) NOT NULL, context_hash CHAR(64) NOT NULL, raw_forms_json LONGTEXT NOT NULL, candidate_state VARCHAR(32) NOT NULL, context_json LONGTEXT NOT NULL, suggestions_json LONGTEXT NOT NULL, occurrences INT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, first_seen_at DATETIME(6) NOT NULL, last_seen_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY dictionary_candidate_uuid (candidate_uuid), UNIQUE KEY dictionary_candidate_term_context (normalized_term,context_hash), KEY dictionary_candidate_review (candidate_state,last_seen_at,id)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_dictionary_mentions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, mention_uuid BINARY(16) NOT NULL, fingerprint CHAR(64) NOT NULL, source_kind VARCHAR(32) NOT NULL, source_id VARCHAR(191) NOT NULL, normalized_term VARCHAR(191) NOT NULL, context_hash CHAR(64) NOT NULL, concept_uuid BINARY(16) NULL, context_json LONGTEXT NOT NULL, strength VARCHAR(16) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY dictionary_mention_uuid (mention_uuid), UNIQUE KEY dictionary_mention_fingerprint (fingerprint), KEY dictionary_mention_source (source_kind,source_id,id), KEY dictionary_mention_term (normalized_term,id), KEY dictionary_mention_concept (concept_uuid,id)) {$c}");

        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }

    public function down(bool $force = false): void
    {
        global $wpdb;
        if ((string) $wpdb->get_var('SELECT DATABASE()') !== 'nhk_v3_test') throw new \RuntimeException('DICTIONARY_MIGRATION_DOWN_REQUIRES_NHK_V3_TEST');
        if (!$force) throw new \RuntimeException('DICTIONARY_MIGRATION_DOWN_REQUIRES_FORCE');
        foreach (['nhk_dictionary_mentions', 'nhk_dictionary_candidates', 'nhk_dictionary_labels', 'nhk_dictionary_concepts'] as $table) $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table);
    }
}
