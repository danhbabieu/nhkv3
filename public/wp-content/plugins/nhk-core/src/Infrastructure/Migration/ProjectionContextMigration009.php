<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

/**
 * Stores legacy semantic projection metadata as non-canonical migration
 * context. Projection bodies are intentionally not part of this schema.
 */
final class ProjectionContextMigration009
{
    public const VERSION = 9;

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'nhk_legacy_projection_contexts';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, source_key VARCHAR(191) NOT NULL, projection_id VARCHAR(64) NOT NULL, semantic_id VARCHAR(191) NOT NULL, canonical_object_id VARCHAR(191) NOT NULL, canonical_object_type VARCHAR(64) NOT NULL, projection_type VARCHAR(64) NOT NULL, visibility VARCHAR(16) NOT NULL, quality_state VARCHAR(32) NOT NULL, seo_ready TINYINT UNSIGNED NOT NULL DEFAULT 0, ai_ready TINYINT UNSIGNED NOT NULL DEFAULT 0, stale TINYINT UNSIGNED NOT NULL DEFAULT 0, provenance_json LONGTEXT NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY source_key_unique (source_key), KEY object_lookup (canonical_object_type,canonical_object_id), KEY projection_state (visibility,quality_state,stale,id)) {$charset}");
        $current = (int) get_option('nhk_core_migration_current', 0);
        $target = (int) get_option('nhk_core_migration_target', 0);
        update_option('nhk_core_migration_current', max($current, self::VERSION), false);
        update_option('nhk_core_migration_target', max($target, self::VERSION), false);
    }
}
