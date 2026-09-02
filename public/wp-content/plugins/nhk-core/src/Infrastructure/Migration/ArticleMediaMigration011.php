<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class ArticleMediaMigration011
{
    public const VERSION = 11;

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'nhk_article_media_blueprints';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, post_id BIGINT UNSIGNED NOT NULL, slot VARCHAR(32) NOT NULL, state VARCHAR(64) NOT NULL, blueprint_json LONGTEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY post_slot_unique (post_id,slot), KEY post_state (post_id,state,slot)) {$charset}");
        $articleTable = $wpdb->prefix . 'nhk_article_operations';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $articleTable)) === $articleTable && !$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$articleTable} LIKE %s", 'diagnostics_json'))) $wpdb->query("ALTER TABLE {$articleTable} ADD COLUMN diagnostics_json LONGTEXT NOT NULL AFTER failure_json");
        $usageTable = $wpdb->prefix . 'nhk_media_usages';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $usageTable)) === $usageTable) {
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$usageTable} LIKE %s", 'alt_text'))) $wpdb->query("ALTER TABLE {$usageTable} ADD COLUMN alt_text VARCHAR(1000) NOT NULL DEFAULT '' AFTER sort_order");
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$usageTable} LIKE %s", 'caption'))) $wpdb->query("ALTER TABLE {$usageTable} ADD COLUMN caption TEXT NOT NULL AFTER alt_text");
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$usageTable} LIKE %s", 'keyword_groups_json'))) $wpdb->query("ALTER TABLE {$usageTable} ADD COLUMN keyword_groups_json LONGTEXT NOT NULL AFTER caption");
            $wpdb->query("UPDATE {$usageTable} SET keyword_groups_json='[]' WHERE keyword_groups_json IS NULL OR keyword_groups_json=''");
        }
        $current = (int) get_option('nhk_core_migration_current', 0);
        $target = (int) get_option('nhk_core_migration_target', 0);
        update_option('nhk_core_migration_current', max($current, self::VERSION), false);
        update_option('nhk_core_migration_target', max($target, self::VERSION), false);
    }

    public function down(bool $force = false): void
    {
        global $wpdb;
        if ((string) $wpdb->get_var('SELECT DATABASE()') !== 'nhk_v3_test') throw new \RuntimeException('ARTICLE_MEDIA_MIGRATION_DOWN_REQUIRES_NHK_V3_TEST');
        if (!$force && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'nhk_article_media_blueprints') > 0) throw new \RuntimeException('ARTICLE_MEDIA_MIGRATION_DOWN_REQUIRES_EMPTY_TABLE');
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'nhk_article_media_blueprints');
        update_option('nhk_core_migration_current', 10, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }
}
