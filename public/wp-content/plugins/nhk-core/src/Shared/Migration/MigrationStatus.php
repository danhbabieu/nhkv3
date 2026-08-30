<?php
declare(strict_types=1);
namespace NHK\Core\Shared\Migration;
final class MigrationStatus {
    public function status(): array {
        return ['current' => (int) get_option('nhk_core_migration_current', 0), 'target' => (int) get_option('nhk_core_migration_target', 2)];
    }
    public function graphStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; $prefix=$wpdb->prefix; foreach (["nhk_graph_nodes","nhk_graph_predicates","nhk_graph_edges"] as $table) if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$prefix.$table)) !== $prefix.$table) return false; return true; }
    public function authorityStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; $name=$wpdb->prefix."nhk_entities"; return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$name)) === $name; }
}
