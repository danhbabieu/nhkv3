<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class PresentationNavigationMigration023
{
    public const VERSION = 23;

    public static function schemaReady(object $wpdb): bool
    {
        $table = $wpdb->prefix . 'nhk_presentation_navigation';
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function up(): void
    {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'PRESENTATION_NAVIGATION_MIGRATION');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'nhk_presentation_navigation';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, navigation_key VARCHAR(64) NOT NULL, canonical_type VARCHAR(64) NOT NULL, canonical_uuid BINARY(16) NOT NULL, parent_id BIGINT UNSIGNED NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0, enabled TINYINT(1) NOT NULL DEFAULT 1, show_in_type_index TINYINT(1) NOT NULL DEFAULT 0, show_in_header_menu TINYINT(1) NOT NULL DEFAULT 0, show_in_mobile_menu TINYINT(1) NOT NULL DEFAULT 0, show_in_sidebar TINYINT(1) NOT NULL DEFAULT 0, featured TINYINT(1) NOT NULL DEFAULT 0, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY navigation_canonical (navigation_key,canonical_uuid), KEY navigation_parent_order (navigation_key,parent_id,enabled,sort_order,id), KEY navigation_placement_order (navigation_key,enabled,sort_order,id)) {$charset}");
        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }
}
