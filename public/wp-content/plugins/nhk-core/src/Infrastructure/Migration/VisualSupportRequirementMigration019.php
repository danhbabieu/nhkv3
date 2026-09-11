<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class VisualSupportRequirementMigration019
{
    public const VERSION = 19;

    public static function schemaReady(object $wpdb): bool
    {
        $table = $wpdb->prefix . 'nhk_visual_support_requirements';
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function up(): void
    {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'VISUAL_SUPPORT_REQUIREMENT_MIGRATION');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$p}nhk_visual_support_requirements (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, requirement_uuid BINARY(16) NOT NULL, subject_type VARCHAR(64) NOT NULL, subject_uuid BINARY(16) NOT NULL, scope VARCHAR(32) NOT NULL, facet VARCHAR(64) NOT NULL, feature_key VARCHAR(64) NOT NULL, visual_intent VARCHAR(64) NOT NULL, state VARCHAR(24) NOT NULL, selected_media_uuid BINARY(16) NULL, selected_media_revision INT UNSIGNED NULL, context_json LONGTEXT NOT NULL, provenance_json LONGTEXT NOT NULL, unresolved_reason VARCHAR(191) NOT NULL, semantic_fingerprint CHAR(64) NOT NULL, idempotency_fingerprint CHAR(64) NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY visual_requirement_uuid (requirement_uuid), UNIQUE KEY visual_requirement_semantic (semantic_fingerprint), UNIQUE KEY visual_requirement_idempotency (idempotency_fingerprint), KEY visual_requirement_reverse (state,subject_type,subject_uuid,scope,facet,feature_key,visual_intent), KEY visual_requirement_subject (subject_type,subject_uuid,scope), KEY visual_requirement_media (selected_media_uuid,state), KEY visual_requirement_admin (state,updated_at)) {$c}");
        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }
}
