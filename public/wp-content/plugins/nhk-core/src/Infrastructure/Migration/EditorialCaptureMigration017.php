<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class EditorialCaptureMigration017
{
    public const VERSION = 17;

    public static function schemaReady(object $wpdb): bool
    {
        $table = $wpdb->prefix . 'nhk_editorial_captures';
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function up(): void
    {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'EDITORIAL_CAPTURE_MIGRATION');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'nhk_editorial_captures';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, capture_uuid BINARY(16) NOT NULL, idempotency_key VARCHAR(191) NOT NULL, request_fingerprint CHAR(64) NOT NULL, stage VARCHAR(40) NOT NULL, status VARCHAR(40) NOT NULL, wp_post_id BIGINT UNSIGNED NULL, wp_state_token CHAR(64) NULL, assets_json LONGTEXT NOT NULL, context_json LONGTEXT NOT NULL, diagnostics_json LONGTEXT NOT NULL, phase_receipts_json LONGTEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY capture_uuid_unique (capture_uuid), UNIQUE KEY capture_idempotency_unique (idempotency_key), KEY capture_stage_status (stage,status,id), KEY capture_post (wp_post_id), KEY capture_revision (capture_uuid,revision)) {$charset}");
        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }

    public function down(bool $force = false): void
    {
        global $wpdb;
        if ((string) $wpdb->get_var('SELECT DATABASE()') !== 'nhk_v3_test') throw new \RuntimeException('EDITORIAL_CAPTURE_MIGRATION_DOWN_REQUIRES_NHK_V3_TEST');
        if (!$force && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'nhk_editorial_captures') > 0) throw new \RuntimeException('EDITORIAL_CAPTURE_MIGRATION_DOWN_REQUIRES_EMPTY_TABLE');
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'nhk_editorial_captures');
        update_option('nhk_core_migration_current', 16, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }
}
