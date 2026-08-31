<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class MigrationLedger006
{
    public const VERSION = 6;

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'nhk_migration_ledger';
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, source_type VARCHAR(64) NOT NULL, source_key VARCHAR(191) NOT NULL, source_checksum CHAR(64) NULL, status VARCHAR(24) NOT NULL, reason_code VARCHAR(64) NOT NULL, target_type VARCHAR(64) NULL, target_key VARCHAR(191) NULL, target_id VARCHAR(191) NULL, batch_no BIGINT UNSIGNED NOT NULL DEFAULT 0, details_json LONGTEXT NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY source_identity (source_type,source_key), KEY ledger_status_reason (status,reason_code,id), KEY ledger_target (target_type,target_key)) {$collate}");
        $current = (int) get_option('nhk_core_migration_current', 0);
        $target = (int) get_option('nhk_core_migration_target', 0);
        update_option('nhk_core_migration_current', max($current, self::VERSION), false);
        update_option('nhk_core_migration_target', max($target, self::VERSION), false);
    }

    public function down(bool $force = false): void
    {
        global $wpdb;
        if ((string) $wpdb->get_var('SELECT DATABASE()') !== 'nhk_v3_test') throw new \RuntimeException('MIGRATION_LEDGER_DOWN_REQUIRES_NHK_V3_TEST');
        if (!$force && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nhk_migration_ledger") > 0) throw new \RuntimeException('MIGRATION_LEDGER_DOWN_REQUIRES_EMPTY_TABLE');
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}nhk_migration_ledger");
        update_option('nhk_core_migration_current', 5, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }
}
