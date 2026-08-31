<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Infrastructure\Authority\{WpdbAuditSink as AuthorityAudit, WpdbAuthorityRepository};
use NHK\Core\Infrastructure\Governance\WpdbAuditSink;
use NHK\Core\Infrastructure\Migration\{AuthorityMigration002, GovernanceMigration003, GraphMigration001};
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\{WpdbAuditSink as GraphAudit, WpdbGraphRepository};
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class P4MigrationAndAuditIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::fail('P4 acceptance requires NHK_WP_TEST_PATH=public; no mandatory skip is allowed.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase(); TestDatabaseGuard::requireTestDatabase();
    }

    public function test_governance_migration_up_is_idempotent_and_has_required_binary_keys(): void
    {
        global $wpdb;
        $migration = new GovernanceMigration003();
        $migration->down(); $migration->up(); $migration->up();
        foreach (['nhk_proposals','nhk_proposal_dependencies','nhk_proposal_approvals','nhk_apply_attempts','nhk_audit_events'] as $table) {
            self::assertSame($wpdb->prefix.$table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix.$table)));
        }
        $columns = $wpdb->get_results("SELECT table_name,column_name,column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name IN ('{$wpdb->prefix}nhk_proposals','{$wpdb->prefix}nhk_proposal_approvals')", ARRAY_A);
        $types = []; foreach ($columns ?: [] as $column) { $name = $column['column_name'] ?? $column['COLUMN_NAME'] ?? ''; $type = $column['column_type'] ?? $column['COLUMN_TYPE'] ?? ''; $types[$name] = strtolower((string) $type); }
        self::assertSame('binary(16)', $types['proposal_uuid'] ?? null); self::assertSame('binary(32)', $types['fingerprint'] ?? null);
        self::assertSame(3, (int) get_option('nhk_core_migration_current'));
    }

    public function test_authority_audit_is_durable_and_uses_shared_event_store(): void
    {
        global $wpdb; (new GraphMigration001())->up(); (new AuthorityMigration002())->up(); (new GovernanceMigration003())->up();
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $audit = new AuthorityAudit(new WpdbAuditSink($wpdb)); $service = new AuthorityService(new WpdbAuthorityRepository($wpdb), $types, $audit);
        $key = 'audit-' . bin2hex(random_bytes(5)); $entity = $service->create('brand', $key, 'Audit'); $entity = $service->rename($entity->canonicalId, 'Audit 2', 1); $entity = $service->retire($entity->canonicalId, 2); $service->reactivate($entity->canonicalId, 3);
        $events = $wpdb->get_col($wpdb->prepare('SELECT event_type FROM '.$wpdb->prefix.'nhk_audit_events WHERE object_type=%s AND object_key=%s ORDER BY id', 'authority', $entity->canonicalId));
        self::assertSame(['AuthorityEntityCreated','AuthorityEntityUpdated','AuthorityEntityRetired','AuthorityEntityReactivated'], $events);
        $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'nhk_entities WHERE canonical_uuid=UNHEX(%s)', str_replace('-', '', $entity->canonicalId)));
        $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'nhk_audit_events WHERE object_type=%s AND object_key=%s', 'authority', $entity->canonicalId));
    }

    public function test_graph_audit_create_retire_reactivate_is_durable(): void
    {
        global $wpdb; (new GraphMigration001())->up(); (new GovernanceMigration003())->up();
        $endpoints = new EndpointTypeRegistry(); $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['a', 'b']));
        $service = new GraphService(new WpdbGraphRepository($wpdb), $endpoints, new PredicateRegistry(), new GraphAudit(new WpdbAuditSink($wpdb)));
        $source = new NodeReference('wp_post', 'a'); $target = new NodeReference('wp_post', 'b'); $edge = $service->create($source, 'about', $target);
        $edge = $service->retire($edge->edge_uuid, 1); $service->reactivate($edge->edge_uuid, 2);
        $events = $wpdb->get_col($wpdb->prepare('SELECT event_type FROM '.$wpdb->prefix.'nhk_audit_events WHERE object_type=%s AND object_key=%s ORDER BY id', 'graph_edge', $edge->edge_uuid));
        self::assertSame(['RelationCreated','RelationRetired','RelationReactivated'], $events);
        $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'nhk_graph_edges WHERE edge_uuid=UNHEX(%s)', str_replace('-', '', $edge->edge_uuid)));
        $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'nhk_graph_nodes WHERE endpoint_type=%s AND endpoint_key IN (%s,%s)', 'wp_post', 'a', 'b'));
        $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'nhk_audit_events WHERE object_type=%s AND object_key=%s', 'graph_edge', $edge->edge_uuid));
    }
}
