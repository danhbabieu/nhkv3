<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class EditorialCaptureAddendumMigration018
{
    public const VERSION = 18;

    public static function schemaReady(object $wpdb): bool
    {
        $table = $wpdb->prefix . 'nhk_editorial_capture_addenda';
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function up(): void
    {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'EDITORIAL_CAPTURE_ADDENDUM_MIGRATION');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix; $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$p}nhk_editorial_capture_addenda (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, addendum_uuid BINARY(16) NOT NULL, capture_uuid BINARY(16) NOT NULL, idempotency_key VARCHAR(191) NOT NULL, request_fingerprint CHAR(64) NOT NULL, status VARCHAR(40) NOT NULL, payload_json LONGTEXT NOT NULL, capture_revision INT UNSIGNED NULL, diagnostics_json LONGTEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY addendum_uuid_unique (addendum_uuid), UNIQUE KEY addendum_idempotency_unique (idempotency_key), KEY addendum_capture (capture_uuid), KEY addendum_revision (capture_uuid,revision)) {$c}");
        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }
}
