<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Migration;
use NHK\Core\Domain\Graph\PredicateRegistry;
final class GraphMigration001 {
    public const VERSION=1;
    public function up(): void {
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $charset=$wpdb->get_charset_collate(); $n=$wpdb->prefix.'nhk_graph_nodes';$p=$wpdb->prefix.'nhk_graph_predicates';$e=$wpdb->prefix.'nhk_graph_edges';
        dbDelta("CREATE TABLE {$n} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, endpoint_type VARCHAR(64) NOT NULL, endpoint_key VARCHAR(191) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY endpoint_unique (endpoint_type,endpoint_key), KEY endpoint_type_id (endpoint_type,id)) {$charset}");
        dbDelta("CREATE TABLE {$p} (id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT, predicate_key VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY predicate_unique (predicate_key)) {$charset}");
        dbDelta("CREATE TABLE {$e} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, edge_uuid BINARY(16) NOT NULL, source_node_id BIGINT UNSIGNED NOT NULL, predicate_id SMALLINT UNSIGNED NOT NULL, target_node_id BIGINT UNSIGNED NOT NULL, state TINYINT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, retired_at DATETIME(6) NULL, PRIMARY KEY (id), UNIQUE KEY edge_uuid_unique (edge_uuid), UNIQUE KEY edge_triple_unique (source_node_id,predicate_id,target_node_id), KEY source_lookup (source_node_id,predicate_id,state,target_node_id), KEY target_lookup (target_node_id,predicate_id,state,source_node_id)) {$charset}");
        foreach((new PredicateRegistry())->all() as $definition){$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$p} (predicate_key,created_at) VALUES (%s,%s)",$definition->key,gmdate('Y-m-d H:i:s.u')));}
        update_option('nhk_core_migration_current',self::VERSION,false); update_option('nhk_core_migration_target',self::VERSION,false);
    }
    public function down(bool $force=false): void { global $wpdb; $e=$wpdb->prefix.'nhk_graph_edges'; if(!$force && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$e}")>0) throw new \RuntimeException('GRAPH_MIGRATION_DOWN_REQUIRES_EMPTY_TABLE'); foreach([$e,$wpdb->prefix.'nhk_graph_predicates',$wpdb->prefix.'nhk_graph_nodes'] as $table)$wpdb->query("DROP TABLE IF EXISTS {$table}"); update_option('nhk_core_migration_current',0,false); }
}
