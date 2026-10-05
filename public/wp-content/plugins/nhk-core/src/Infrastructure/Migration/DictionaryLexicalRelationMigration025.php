<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Migration;
final class DictionaryLexicalRelationMigration025
{
    public const VERSION=25;
    public static function schemaReady(object $wpdb):bool { $t=$wpdb->prefix.'nhk_dictionary_lexical_relations'; return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))===$t; }
    public function up():void { global $wpdb; MigrationDatabaseGuard::assertUpAllowed((string)$wpdb->get_var('SELECT DATABASE()'),'DICTIONARY_LEXICAL_RELATION_MIGRATION'); require_once ABSPATH.'wp-admin/includes/upgrade.php'; $p=$wpdb->prefix;$c=$wpdb->get_charset_collate(); dbDelta("CREATE TABLE {$p}nhk_dictionary_lexical_relations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, relation_uuid BINARY(16) NOT NULL, source_entry_uuid BINARY(16) NOT NULL, source_sense_uuid BINARY(16) NULL, target_entry_uuid BINARY(16) NOT NULL, target_sense_uuid BINARY(16) NULL, relation_kind VARCHAR(32) NOT NULL, provenance_json LONGTEXT NOT NULL, idempotency_key VARCHAR(191) NOT NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, retired_at DATETIME(6) NULL, PRIMARY KEY(id), UNIQUE KEY relation_uuid_unique(relation_uuid), UNIQUE KEY idempotency_key_unique(idempotency_key), KEY source_lookup(source_entry_uuid,source_sense_uuid,state,id), KEY target_lookup(target_entry_uuid,target_sense_uuid,state,id), KEY kind_lookup(relation_kind,state,id)) {$c}"); update_option('nhk_core_migration_current',max((int)get_option('nhk_core_migration_current',0),self::VERSION),false); update_option('nhk_core_migration_target',max((int)get_option('nhk_core_migration_target',0),self::VERSION),false); }
}
