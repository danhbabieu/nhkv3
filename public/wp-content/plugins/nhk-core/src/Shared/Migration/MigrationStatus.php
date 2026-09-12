<?php
declare(strict_types=1);
namespace NHK\Core\Shared\Migration;
final class MigrationStatus {
    private ?bool $projectionReady = null;
    public function status(): array {
        return ['current' => (int) get_option('nhk_core_migration_current', 0), 'target' => (int) get_option('nhk_core_migration_target', 20)];
    }
    /** Runtime writes fail closed when the ledger is current but a required table/column is missing. */
    public function runtimeSchemaReady(): bool {
        global $wpdb;
        $state = $this->status();
        if (!isset($wpdb) || !is_object($wpdb) || $state['current'] < $state['target']) return false;
        foreach (['nhk_proposals', 'nhk_editorial_captures', 'nhk_editorial_capture_addenda'] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $table)) !== $wpdb->prefix . $table) return false;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s',
            $wpdb->prefix . 'nhk_proposals', 'subject_id',
        )) === 1;
    }
    public function graphStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; $prefix=$wpdb->prefix; foreach (["nhk_graph_nodes","nhk_graph_predicates","nhk_graph_edges"] as $table) if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$prefix.$table)) !== $prefix.$table) return false; return true; }
    public function authorityStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; $name=$wpdb->prefix."nhk_entities"; return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$name)) === $name; }
    public function governanceStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; foreach (['nhk_proposals','nhk_proposal_dependencies','nhk_proposal_approvals','nhk_apply_attempts','nhk_audit_events'] as $table) if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$table)) !== $wpdb->prefix.$table) return false; return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s',$wpdb->prefix.'nhk_proposals','subject_id')) === 1; }
    public function mediaStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; foreach (['nhk_media','nhk_media_assets','nhk_media_usages'] as $table) if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$table)) !== $wpdb->prefix.$table) return false; return true; }
    public function videoStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; $table=$wpdb->prefix.'nhk_videos'; return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)) === $table; }
    public function knowledgeStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; foreach (['nhk_knowledge_claims','nhk_sources','nhk_evidence'] as $table) if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$table)) !== $wpdb->prefix.$table) return false; return true; }
    public function articleStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; $table=$wpdb->prefix.'nhk_article_operations'; return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)) === $table; }
    public function articleMediaStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; $table=$wpdb->prefix.'nhk_article_media_blueprints'; return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)) === $table; }
    public function visualSupportStorageReady(): bool { global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return false; $table=$wpdb->prefix.'nhk_visual_support_requirements'; return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)) === $table; }
    public function projectionStorageReady(): bool { if ($this->projectionReady !== null) return $this->projectionReady; global $wpdb; if (!isset($wpdb) || !is_object($wpdb)) return $this->projectionReady = false; foreach (['nhk_claim_projection_revisions','nhk_claim_projection_dependencies'] as $table) if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$table)) !== $wpdb->prefix.$table) return $this->projectionReady = false; return $this->projectionReady = true; }
}
