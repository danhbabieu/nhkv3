<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class MediaMigration004
{
    public const VERSION = 4;

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$p}nhk_media (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, canonical_uuid BINARY(16) NOT NULL, stable_key VARCHAR(191) NOT NULL, canonical_name VARCHAR(255) NOT NULL, readiness VARCHAR(16) NOT NULL, provenance_json LONGTEXT NOT NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY canonical_uuid_unique (canonical_uuid), UNIQUE KEY stable_key_unique (stable_key), KEY readiness_state_id (readiness,state,id)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_media_assets (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, asset_uuid BINARY(16) NOT NULL, media_id BIGINT UNSIGNED NOT NULL, asset_kind VARCHAR(16) NOT NULL, storage_key VARCHAR(255) NOT NULL, checksum BINARY(32) NOT NULL, mime_type VARCHAR(128) NOT NULL, byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0, width INT UNSIGNED NULL, height INT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY asset_uuid_unique (asset_uuid), UNIQUE KEY media_storage_unique (media_id,storage_key), KEY checksum_lookup (checksum), KEY media_kind (media_id,asset_kind,id)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_media_usages (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, usage_uuid BINARY(16) NOT NULL, media_id BIGINT UNSIGNED NOT NULL, endpoint_type VARCHAR(64) NOT NULL, endpoint_key VARCHAR(191) NOT NULL, usage_role VARCHAR(32) NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY usage_uuid_unique (usage_uuid), UNIQUE KEY media_usage_unique (media_id,endpoint_type,endpoint_key,usage_role), KEY endpoint_lookup (endpoint_type,endpoint_key,usage_role,id)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_videos (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, canonical_uuid BINARY(16) NOT NULL, platform VARCHAR(32) NOT NULL, external_video_id VARCHAR(128) NOT NULL, canonical_url VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, metadata_json LONGTEXT NOT NULL, thumbnail_media_uuid BINARY(16) NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY canonical_uuid_unique (canonical_uuid), UNIQUE KEY external_video_unique (platform,external_video_id), KEY state_id (state,id)) {$c}");
        update_option('nhk_core_migration_current', self::VERSION, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }

    public function down(bool $force = false): void
    {
        global $wpdb;
        if ((string) $wpdb->get_var('SELECT DATABASE()') !== 'nhk_v3_test') throw new \RuntimeException('MEDIA_MIGRATION_DOWN_REQUIRES_NHK_V3_TEST');
        $p = $wpdb->prefix;
        foreach (['nhk_videos', 'nhk_media_usages', 'nhk_media_assets', 'nhk_media'] as $table) $wpdb->query("DROP TABLE IF EXISTS {$p}{$table}");
        update_option('nhk_core_migration_current', 3, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }
}
