<?php
declare(strict_types=1);
namespace NHK\Tests\Integration;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry,NodeReference,PredicateRegistry};
use NHK\Core\Infrastructure\Graph\{InMemoryAuditSink,WpPostEndpointResolver,WpdbGraphRepository};
use PHPUnit\Framework\TestCase;
final class GraphWpdbIntegrationTest extends TestCase {
    protected function setUp(): void { if (!getenv('NHK_WP_TEST_PATH')) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.'); require_once rtrim((string)getenv('NHK_WP_TEST_PATH'),'/').'/wp-load.php'; }
    public function test_migration_tables_and_wp_post_graph_round_trip(): void { global $wpdb; foreach(['nhk_graph_nodes','nhk_graph_predicates','nhk_graph_edges'] as $table) self::assertSame($wpdb->prefix.$table,$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$table))); $postId=wp_insert_post(['post_title'=>'NHK Graph Integration Fixture','post_status'=>'draft']);$targetId=wp_insert_post(['post_title'=>'NHK Graph Integration Target','post_status'=>'draft']); self::assertGreaterThan(0,$postId); self::assertGreaterThan(0,$targetId); $repo=new WpdbGraphRepository($wpdb);$types=new EndpointTypeRegistry();$types->register('wp_post',new WpPostEndpointResolver());$audit=new InMemoryAuditSink();$service=new GraphService($repo,$types,new PredicateRegistry(),$audit);$source=new NodeReference('wp_post',get_current_blog_id().':'.$postId);$target=new NodeReference('wp_post',get_current_blog_id().':'.$targetId);$edge=$service->create($source,'about',$target);self::assertNotNull($repo->findByUuid($edge->edge_uuid));$retired=$service->retire($edge->edge_uuid,1);self::assertFalse($retired->isActive());$active=$service->reactivate($edge->edge_uuid,2);self::assertTrue($active->isActive());$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_graph_edges WHERE edge_uuid=UNHEX(%s)",str_replace('-','',$edge->edge_uuid)));$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_graph_nodes WHERE endpoint_key IN (%s,%s)",get_current_blog_id().':'.$postId,get_current_blog_id().':'.$targetId));wp_delete_post($postId,true);wp_delete_post($targetId,true); }
}
