<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class MediaUsageMetadataMigration021
{
    public const VERSION = 21;

    public static function schemaReady(object $wpdb): bool
    {
        $table = $wpdb->prefix . 'nhk_media_usages';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;
        $title = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'title'));
        $revision = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'revision'));
        return $title !== null && $revision !== null;
    }

    public function up(): void
    {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'MEDIA_USAGE_METADATA_MIGRATION');
        $table = $wpdb->prefix . 'nhk_media_usages';
        if ($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'title')) === null && $wpdb->query("ALTER TABLE {$table} ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT '' AFTER caption") === false) throw new \RuntimeException('MEDIA_USAGE_TITLE_COLUMN_ADD_FAILED');
        if ($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'revision')) === null && $wpdb->query("ALTER TABLE {$table} ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 1 AFTER keyword_groups_json") === false) throw new \RuntimeException('MEDIA_USAGE_REVISION_COLUMN_ADD_FAILED');
        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }
}
