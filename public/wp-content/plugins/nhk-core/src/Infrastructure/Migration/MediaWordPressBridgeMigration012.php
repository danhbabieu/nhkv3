<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class MediaWordPressBridgeMigration012
{
    public const VERSION = 12;

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'nhk_media_wordpress_attachments';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, media_uuid BINARY(16) NOT NULL, asset_uuid BINARY(16) NOT NULL, attachment_id BIGINT UNSIGNED NOT NULL, storage_key VARCHAR(255) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY media_unique (media_uuid), UNIQUE KEY attachment_unique (attachment_id), KEY asset_lookup (asset_uuid), KEY storage_lookup (storage_key)) {$charset}");
        $current = (int) get_option('nhk_core_migration_current', 0);
        $target = (int) get_option('nhk_core_migration_target', 0);
        update_option('nhk_core_migration_current', max($current, self::VERSION), false);
        update_option('nhk_core_migration_target', max($target, self::VERSION), false);
    }
}
