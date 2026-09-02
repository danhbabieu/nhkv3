<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class ArticleIngestMigration010
{
    public const VERSION = 10;

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'nhk_article_operations';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, operation_id CHAR(36) NOT NULL, idempotency_key VARCHAR(191) NOT NULL, request_fingerprint CHAR(64) NOT NULL, intent VARCHAR(24) NOT NULL, wp_endpoint_key VARCHAR(191) NULL, wp_post_id BIGINT UNSIGNED NULL, stage VARCHAR(32) NOT NULL, outcome VARCHAR(64) NOT NULL, retryable TINYINT UNSIGNED NOT NULL DEFAULT 0, proposal_ids_json LONGTEXT NOT NULL, applied_proposal_ids_json LONGTEXT NOT NULL, failure_json LONGTEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY operation_unique (operation_id), UNIQUE KEY idempotency_unique (idempotency_key), KEY stage_outcome (stage,outcome,id), KEY wp_post_lookup (wp_endpoint_key,wp_post_id), KEY revision_lookup (operation_id,revision)) {$charset}");
        $current = (int) get_option('nhk_core_migration_current', 0);
        $target = (int) get_option('nhk_core_migration_target', 0);
        update_option('nhk_core_migration_current', max($current, self::VERSION), false);
        update_option('nhk_core_migration_target', max($target, self::VERSION), false);
    }

    public function down(bool $force = false): void
    {
        global $wpdb;
        if ((string) $wpdb->get_var('SELECT DATABASE()') !== 'nhk_v3_test') throw new \RuntimeException('ARTICLE_INGEST_MIGRATION_DOWN_REQUIRES_NHK_V3_TEST');
        if (!$force && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'nhk_article_operations') > 0) throw new \RuntimeException('ARTICLE_INGEST_MIGRATION_DOWN_REQUIRES_EMPTY_TABLE');
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'nhk_article_operations');
        update_option('nhk_core_migration_current', 9, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }
}
