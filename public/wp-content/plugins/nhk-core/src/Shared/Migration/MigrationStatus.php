<?php
declare(strict_types=1);
namespace NHK\Core\Shared\Migration;
final class MigrationStatus {
    public function status(): array {
        return ['current' => (int) get_option('nhk_core_migration_current', 0), 'target' => (int) get_option('nhk_core_migration_target', 6)];
    }
    public function graphStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; $prefix=$wpdb->prefix; foreach (["nhk_graph_nodes","nhk_graph_predicates","nhk_graph_edges"] as $table) if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$prefix.$table)) !== $prefix.$table) return false; return true; }
    public function authorityStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; $name=$wpdb->prefix."nhk_entities"; return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$name)) === $name; }
    public function governanceStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; foreach (['nhk_proposals','nhk_proposal_dependencies','nhk_proposal_approvals','nhk_apply_attempts','nhk_audit_events'] as $table) if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$table)) !== $wpdb->prefix.$table) return false; return true; }
    public function mediaStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; foreach (['nhk_media','nhk_media_assets','nhk_media_usages'] as $table) if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$table)) !== $wpdb->prefix.$table) return false; return true; }
    public function videoStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; $table=$wpdb->prefix.'nhk_videos'; return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)) === $table; }
    public function knowledgeStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; foreach (['nhk_knowledge_claims','nhk_sources','nhk_evidence'] as $table) if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$table)) !== $wpdb->prefix.$table) return false; return true; }
}
