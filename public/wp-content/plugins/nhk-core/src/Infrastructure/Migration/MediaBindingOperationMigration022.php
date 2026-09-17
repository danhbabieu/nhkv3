<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class MediaBindingOperationMigration022
{
    public const VERSION = 22;

    public static function schemaReady(object $wpdb): bool
    {
        $usage = $wpdb->prefix . 'nhk_media_usages';
        $operations = $wpdb->prefix . 'nhk_media_binding_operations';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $operations)) !== $operations) return false;
        foreach (['active_slot', 'selection_source', 'selection_policy'] as $column) if ($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$usage} LIKE %s", $column)) === null) return false;
        return $wpdb->get_var("SHOW INDEX FROM {$usage} WHERE Key_name='media_usage_active_slot_unique'") !== null;
    }

    public function up(): void
    {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'MEDIA_BINDING_OPERATION_MIGRATION');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $usage = $wpdb->prefix . 'nhk_media_usages';
        $charset = $wpdb->get_charset_collate();
        if ($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$usage} LIKE %s", 'active_slot')) === null && $wpdb->query("ALTER TABLE {$usage} ADD COLUMN active_slot VARCHAR(32) NULL AFTER placement_key") === false) throw new \RuntimeException('MEDIA_USAGE_ACTIVE_SLOT_COLUMN_ADD_FAILED');
        if ($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$usage} LIKE %s", 'selection_source')) === null && $wpdb->query("ALTER TABLE {$usage} ADD COLUMN selection_source VARCHAR(24) NOT NULL DEFAULT 'SYSTEM_AUTO' AFTER placement_key") === false) throw new \RuntimeException('MEDIA_USAGE_SELECTION_SOURCE_COLUMN_ADD_FAILED');
        if ($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$usage} LIKE %s", 'selection_policy')) === null && $wpdb->query("ALTER TABLE {$usage} ADD COLUMN selection_policy VARCHAR(16) NOT NULL DEFAULT 'AUTO' AFTER selection_source") === false) throw new \RuntimeException('MEDIA_USAGE_SELECTION_POLICY_COLUMN_ADD_FAILED');
        if ($wpdb->get_var("SHOW INDEX FROM {$usage} WHERE Key_name='media_usage_active_slot_unique'") === null && $wpdb->query("ALTER TABLE {$usage} ADD UNIQUE KEY media_usage_active_slot_unique (endpoint_type,endpoint_key,usage_role,active_slot)") === false) throw new \RuntimeException('MEDIA_USAGE_ACTIVE_SLOT_INDEX_ADD_FAILED');
        $table = $wpdb->prefix . 'nhk_media_binding_operations';
        dbDelta("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, operation_uuid BINARY(16) NOT NULL, idempotency_key VARCHAR(191) NOT NULL, request_fingerprint BINARY(32) NOT NULL, media_uuid BINARY(16) NULL, target_type VARCHAR(64) NOT NULL, target_uuid BINARY(16) NOT NULL, requested_role VARCHAR(32) NOT NULL, selection_source VARCHAR(24) NOT NULL, selection_policy VARCHAR(16) NOT NULL, stage VARCHAR(32) NOT NULL, status VARCHAR(24) NOT NULL, resulting_usage_uuid BINARY(16) NULL, previous_usage_uuid BINARY(16) NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, error_code VARCHAR(191) NULL, metadata_json LONGTEXT NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY operation_uuid_unique (operation_uuid), UNIQUE KEY operation_idempotency (idempotency_key), KEY operation_target (target_type,target_uuid,stage,status), KEY operation_media (media_uuid,status)) {$charset}");
        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }
}
