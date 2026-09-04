<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Migration;

final class PublicIdentityMigration014
{
    public const VERSION = 14;

    public static function schemaReady(object $wpdb): bool
    {
        foreach (['nhk_public_identities', 'nhk_public_identity_history'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;
        }

        return true;
    }

    public function up(): void
    {
        global $wpdb;
        $database = (string) $wpdb->get_var('SELECT DATABASE()');
        if (!in_array($database, ['nhk_v3', 'nhk_v3_test'], true)) throw new \RuntimeException('PUBLIC_IDENTITY_MIGRATION_UP_REQUIRES_NHK_V3_OR_TEST');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}nhk_public_identities (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, identity_uuid BINARY(16) NOT NULL, owner_kind VARCHAR(64) NOT NULL, owner_uuid BINARY(16) NOT NULL, route_type VARCHAR(64) NOT NULL, current_slug VARCHAR(191) NOT NULL, collision_scope VARCHAR(191) NOT NULL, route_policy_version VARCHAR(64) NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, idempotency_key VARCHAR(191) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY identity_unique (identity_uuid), UNIQUE KEY owner_unique (owner_kind,owner_uuid,route_type), UNIQUE KEY route_unique (route_type,collision_scope,current_slug), UNIQUE KEY idempotency_unique (idempotency_key)) {$charset}");
        dbDelta("CREATE TABLE {$wpdb->prefix}nhk_public_identity_history (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, identity_uuid BINARY(16) NOT NULL, route_type VARCHAR(64) NOT NULL, route_path VARCHAR(255) NOT NULL, old_slug VARCHAR(191) NOT NULL, revision INT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY history_route (route_type,route_path), KEY identity_lookup (identity_uuid)) {$charset}");
        update_option('nhk_core_migration_current', max((int)get_option('nhk_core_migration_current',0),self::VERSION), false);
        update_option('nhk_core_migration_target', max((int)get_option('nhk_core_migration_target',0),self::VERSION), false);
    }
    public function down(bool $force=false): void
    {
        global $wpdb;
        if ((string)$wpdb->get_var('SELECT DATABASE()')!=='nhk_v3_test') throw new \RuntimeException('PUBLIC_IDENTITY_MIGRATION_DOWN_REQUIRES_NHK_V3_TEST');
        if (!$force) throw new \RuntimeException('PUBLIC_IDENTITY_MIGRATION_DOWN_REQUIRES_FORCE');
        $wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'nhk_public_identity_history');
        $wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'nhk_public_identities');
    }
}
