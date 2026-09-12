<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

final class GovernanceSubjectBindingMigration020
{
    public const VERSION = 20;

    public function up(): void
    {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'GOVERNANCE_SUBJECT_BINDING_MIGRATION');
        $table = $wpdb->prefix . 'nhk_proposals';
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s',
            $table,
            'subject_id',
        ));
        if ($exists === 0) {
            // Forward-only schema repair. Existing values are intentionally
            // preserved: entity_type is a dispatch label, never a safe UUID
            // backfill source. Malformed historical rows remain visible to
            // ProposalSubjectBindingValidator and the governed repair path.
            $wpdb->query("ALTER TABLE {$table} ADD subject_id VARCHAR(191) NULL AFTER entity_type");
            if ((string) $wpdb->last_error !== '') throw new \RuntimeException('GOVERNANCE_SUBJECT_BINDING_COLUMN_FAILED: ' . $wpdb->last_error);
        }
        update_option('nhk_core_migration_current', max((int) get_option('nhk_core_migration_current', 0), self::VERSION), false);
        update_option('nhk_core_migration_target', max((int) get_option('nhk_core_migration_target', 0), self::VERSION), false);
    }

    public static function schemaReady(object $wpdb): bool
    {
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s',
            $wpdb->prefix . 'nhk_proposals',
            'subject_id',
        )) === 1;
    }
}
