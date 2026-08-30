<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Migration;
final class AuthorityMigration002 {
 public const VERSION=2;
 public function up():void{global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$t=$wpdb->prefix.'nhk_entities';$c=$wpdb->get_charset_collate();dbDelta("CREATE TABLE {$t} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, canonical_uuid BINARY(16) NOT NULL, entity_type VARCHAR(64) NOT NULL, stable_key VARCHAR(191) NOT NULL, canonical_name VARCHAR(255) NOT NULL, schema_version SMALLINT UNSIGNED NOT NULL, payload LONGTEXT NOT NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, retired_at DATETIME(6) NULL, PRIMARY KEY (id), UNIQUE KEY canonical_uuid_unique (canonical_uuid), UNIQUE KEY entity_stable_unique (entity_type,stable_key), KEY type_state_id (entity_type,state,id), KEY type_name (entity_type,canonical_name)) {$c}");update_option('nhk_core_migration_current',self::VERSION,false);update_option('nhk_core_migration_target',self::VERSION,false);}
 public function down(bool $force=false):void{global $wpdb;$t=$wpdb->prefix.'nhk_entities';if(!$force&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}")>0)throw new \RuntimeException('AUTHORITY_MIGRATION_DOWN_REQUIRES_EMPTY_TABLE');$wpdb->query("DROP TABLE IF EXISTS {$t}");update_option('nhk_core_migration_current',1,false);update_option('nhk_core_migration_target',self::VERSION,false);}
}
