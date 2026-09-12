<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Infrastructure\Migration\{ClaimProjectionMigration016, DictionaryMigration015, EditorialCaptureAddendumMigration018, GovernanceSubjectBindingMigration020, PublicIdentityMigration014, VisualSupportRequirementMigration019};
use NHK\Core\Plugin;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class MaintenanceMigrationIntegrationTest extends TestCase
{
    private int $previousCurrent = 0;
    private int $previousTarget = 0;

    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        require_once dirname(__DIR__, 2) . '/nhk-core.php';

        global $wpdb;
        $this->previousCurrent = (int) get_option('nhk_core_migration_current', 0);
        $this->previousTarget = (int) get_option('nhk_core_migration_target', 0);
        (new ClaimProjectionMigration016())->down(true);
        (new PublicIdentityMigration014())->up();
        (new DictionaryMigration015())->up();
        update_option('nhk_core_migration_current', 15, false);
        update_option('nhk_core_migration_target', 15, false);
        self::assertSame('nhk_v3_test', (string) $wpdb->get_var('SELECT DATABASE()'));
    }

    protected function tearDown(): void
    {
        update_option('nhk_core_migration_current', $this->previousCurrent, false);
        update_option('nhk_core_migration_target', $this->previousTarget, false);
    }

    public function test_pending_runner_moves_15_to_19_with_the_projection_capture_and_visual_support_schema_contract(): void
    {
        global $wpdb;
        $before = $this->canonicalCounts();

        Plugin::runPendingMigrations();

        self::assertSame(20, (int) get_option('nhk_core_migration_current', 0));
        self::assertSame(20, (int) get_option('nhk_core_migration_target', 0));
        self::assertTrue(ClaimProjectionMigration016::schemaReady($wpdb));
        self::assertTrue(\NHK\Core\Infrastructure\Migration\EditorialCaptureMigration017::schemaReady($wpdb));
        self::assertTrue(EditorialCaptureAddendumMigration018::schemaReady($wpdb));
        self::assertTrue(VisualSupportRequirementMigration019::schemaReady($wpdb));
        self::assertTrue(GovernanceSubjectBindingMigration020::schemaReady($wpdb));
        self::assertSame([
            'PRIMARY',
            'projection_node_revision',
            'projection_node_status',
            'projection_input_hash',
        ], $this->indexNames($wpdb->prefix . 'nhk_claim_projection_revisions'));
        self::assertSame([
            'PRIMARY',
            'projection_dependency_unique',
            'projection_dependency_lookup',
            'projection_dependency_node',
        ], $this->indexNames($wpdb->prefix . 'nhk_claim_projection_dependencies'));
        self::assertSame($before, $this->canonicalCounts());
    }

    public function test_second_pending_runner_is_idempotent_and_does_not_change_canonical_counts(): void
    {
        global $wpdb;
        Plugin::runPendingMigrations();
        $firstSchema = $this->createStatements();
        $firstCounts = $this->canonicalCounts();

        Plugin::runPendingMigrations();

        self::assertSame(20, (int) get_option('nhk_core_migration_current', 0));
        self::assertTrue(GovernanceSubjectBindingMigration020::schemaReady($wpdb));
        self::assertSame(20, (int) get_option('nhk_core_migration_target', 0));
        self::assertSame($firstSchema, $this->createStatements());
        self::assertSame($firstCounts, $this->canonicalCounts());
    }

    /** @return array<string,int> */
    private function canonicalCounts(): array
    {
        global $wpdb;
        $counts = [];
        foreach (['nhk_entities', 'nhk_graph_nodes', 'nhk_graph_edges', 'nhk_knowledge_claims', 'nhk_sources', 'nhk_evidence'] as $suffix) {
            $counts[$suffix] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . $suffix);
        }
        return $counts;
    }

    /** @return list<string> */
    private function indexNames(string $table): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SHOW INDEX FROM ' . $table, ARRAY_A) ?: [];
        return array_values(array_unique(array_column($rows, 'Key_name')));
    }

    /** @return array<string,string> */
    private function createStatements(): array
    {
        global $wpdb;
        $statements = [];
        foreach (['nhk_claim_projection_revisions', 'nhk_claim_projection_dependencies'] as $suffix) {
            $row = $wpdb->get_row('SHOW CREATE TABLE ' . $wpdb->prefix . $suffix, ARRAY_N);
            $statements[$suffix] = (string) ($row[1] ?? '');
        }
        return $statements;
    }
}
