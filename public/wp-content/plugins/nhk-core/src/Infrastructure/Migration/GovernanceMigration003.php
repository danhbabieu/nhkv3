<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class GovernanceMigration003
{
    public const VERSION = 3;

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate(); $p = $wpdb->prefix;
        dbDelta("CREATE TABLE {$p}nhk_proposals (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, proposal_uuid BINARY(16) NOT NULL, idempotency_key VARCHAR(191) NOT NULL, operation VARCHAR(64) NOT NULL, entity_type VARCHAR(64) NOT NULL, target_uuid BINARY(16) NULL, expected_revision INT UNSIGNED NULL, command_json LONGTEXT NOT NULL, fingerprint BINARY(32) NOT NULL, state TINYINT UNSIGNED NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, created_by BIGINT UNSIGNED NOT NULL, submitted_at DATETIME(6) NULL, applied_at DATETIME(6) NULL, cancelled_at DATETIME(6) NULL, rejected_at DATETIME(6) NULL, superseded_at DATETIME(6) NULL, superseded_by_proposal_id BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY proposal_uuid_unique (proposal_uuid), UNIQUE KEY idempotency_key_unique (idempotency_key), KEY state_id (state,id), KEY entity_state_id (entity_type,state,id), KEY target_state (target_uuid,state)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_proposal_dependencies (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, proposal_id BIGINT UNSIGNED NOT NULL, depends_on_proposal_id BINARY(16) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY proposal_dependency_unique (proposal_id,depends_on_proposal_id), KEY dependency_lookup (depends_on_proposal_id,proposal_id)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_proposal_approvals (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, approval_uuid BINARY(16) NOT NULL, proposal_id BIGINT UNSIGNED NOT NULL, proposal_revision INT UNSIGNED NOT NULL, fingerprint BINARY(32) NOT NULL, approved_by BIGINT UNSIGNED NOT NULL, approved_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY approval_uuid_unique (approval_uuid), KEY proposal_revision (proposal_id,proposal_revision)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_apply_attempts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, attempt_uuid BINARY(16) NOT NULL, proposal_id BIGINT UNSIGNED NOT NULL, attempt_no INT UNSIGNED NOT NULL, state TINYINT UNSIGNED NOT NULL, result_entity_uuid BINARY(16) NULL, error_code VARCHAR(64) NULL, error_message TEXT NULL, started_at DATETIME(6) NULL, finished_at DATETIME(6) NULL, PRIMARY KEY (id), UNIQUE KEY attempt_uuid_unique (attempt_uuid), UNIQUE KEY proposal_attempt (proposal_id,attempt_no), KEY proposal_state (proposal_id,state)) {$c}");
        dbDelta("CREATE TABLE {$p}nhk_audit_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_uuid BINARY(16) NOT NULL, event_type VARCHAR(96) NOT NULL, object_type VARCHAR(64) NOT NULL, object_key VARCHAR(191) NOT NULL, actor_user_id BIGINT UNSIGNED NULL, context_json LONGTEXT NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (id), UNIQUE KEY event_uuid_unique (event_uuid), KEY object_lookup (object_type,object_key,id), KEY event_lookup (event_type,id), KEY actor_lookup (actor_user_id,id)) {$c}");
        update_option('nhk_core_migration_current', self::VERSION, false); update_option('nhk_core_migration_target', self::VERSION, false);
    }

    public function down(bool $force = false): void
    {
        global $wpdb;
        $database = (string) $wpdb->get_var('SELECT DATABASE()');
        if ($database !== 'nhk_v3_test') {
            throw new \RuntimeException('GOVERNANCE_MIGRATION_DOWN_REQUIRES_NHK_V3_TEST');
        }
        $p = $wpdb->prefix;
        foreach (['nhk_audit_events', 'nhk_apply_attempts', 'nhk_proposal_approvals', 'nhk_proposal_dependencies', 'nhk_proposals'] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$p}{$table}");
        }
        update_option('nhk_core_migration_current', 2, false);
        update_option('nhk_core_migration_target', self::VERSION, false);
    }
}
