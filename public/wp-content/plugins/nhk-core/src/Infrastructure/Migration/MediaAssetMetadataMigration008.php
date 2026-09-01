<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class MediaAssetMetadataMigration008
{
    public const VERSION = 8;

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'nhk_media_assets';
        $charset = $wpdb->get_charset_collate();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            dbDelta("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, asset_uuid BINARY(16) NOT NULL, media_id BIGINT UNSIGNED NOT NULL, asset_kind VARCHAR(16) NOT NULL, storage_key VARCHAR(255) NOT NULL, checksum BINARY(32) NOT NULL, mime_type VARCHAR(128) NOT NULL, byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0, width INT UNSIGNED NULL, height INT UNSIGNED NULL, visibility VARCHAR(16) NOT NULL DEFAULT 'PRIVATE', metadata_json LONGTEXT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY asset_uuid_unique (asset_uuid), UNIQUE KEY media_storage_unique (media_id,storage_key), KEY checksum_lookup (checksum), KEY media_kind (media_id,asset_kind,id)) {$charset}");
        }
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'visibility')) && $wpdb->query("ALTER TABLE {$table} ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'PRIVATE' AFTER height") === false) throw new \RuntimeException('MEDIA_ASSET_VISIBILITY_COLUMN_ADD_FAILED');
        $visibilityDefault = $wpdb->get_var($wpdb->prepare("SELECT column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name='visibility'", $table));
        if (strtoupper(trim((string) $visibilityDefault, "'")) !== 'PRIVATE' && $wpdb->query("ALTER TABLE {$table} ALTER COLUMN visibility SET DEFAULT 'PRIVATE'") === false) throw new \RuntimeException('MEDIA_ASSET_VISIBILITY_DEFAULT_UPDATE_FAILED');
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'metadata_json')) && $wpdb->query("ALTER TABLE {$table} ADD COLUMN metadata_json LONGTEXT NULL AFTER visibility") === false) throw new \RuntimeException('MEDIA_ASSET_METADATA_COLUMN_ADD_FAILED');
        update_option('nhk_core_migration_current', self::VERSION, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }
}
