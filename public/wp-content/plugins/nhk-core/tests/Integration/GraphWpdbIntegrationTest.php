<?php
declare(strict_types=1);
namespace NHK\Tests\Integration;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry,NodeReference,PredicateRegistry};
use NHK\Core\Infrastructure\Graph\{InMemoryAuditSink,WpPostEndpointResolver,WpdbGraphRepository};
use NHK\Core\Infrastructure\Migration\{GraphMigration001,AuthorityMigration002};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Domain\Authority\{EntityTypeDefinition,EntityTypeRegistry};
use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Authority\Exception\AuthorityRevisionConflict;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;
final class GraphWpdbIntegrationTest extends TestCase {
    protected function setUp(): void { if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.'); require_once rtrim((string)getenv('NHK_WP_TEST_PATH'),'/').'/wp-load.php'; TestDatabaseGuard::selectTestDatabase(); TestDatabaseGuard::requireTestDatabase(); (new GraphMigration001())->up(); (new AuthorityMigration002())->up(); }
    public function test_migration_tables_and_wp_post_graph_round_trip(): void { global $wpdb; foreach(['nhk_graph_nodes','nhk_graph_predicates','nhk_graph_edges'] as $table) self::assertSame($wpdb->prefix.$table,$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$table))); $postId=wp_insert_post(['post_title'=>'NHK Graph Integration Fixture','post_status'=>'draft']);$targetId=wp_insert_post(['post_title'=>'NHK Graph Integration Target','post_status'=>'draft']); self::assertGreaterThan(0,$postId); self::assertGreaterThan(0,$targetId); $repo=new WpdbGraphRepository($wpdb);$types=new EndpointTypeRegistry();$types->register('wp_post',new WpPostEndpointResolver());$audit=new InMemoryAuditSink();$service=new GraphService($repo,$types,new PredicateRegistry(),$audit);$source=new NodeReference('wp_post',get_current_blog_id().':'.$postId);$target=new NodeReference('wp_post',get_current_blog_id().':'.$targetId);$edge=$service->create($source,'about',$target);self::assertNotNull($repo->findByUuid($edge->edge_uuid));$retired=$service->retire($edge->edge_uuid,1);self::assertFalse($retired->isActive());$active=$service->reactivate($edge->edge_uuid,2);self::assertTrue($active->isActive());$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_graph_edges WHERE edge_uuid=UNHEX(%s)",str_replace('-','',$edge->edge_uuid)));$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_graph_nodes WHERE endpoint_key IN (%s,%s)",get_current_blog_id().':'.$postId,get_current_blog_id().':'.$targetId));wp_delete_post($postId,true);wp_delete_post($targetId,true); }
    public function test_authority_uuid_stable_key_and_optimistic_lock_persist_in_db(): void { $types=new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand',1,true,['description'])); $service=new AuthorityService(new WpdbAuthorityRepository(),$types); $key='integration-'.bin2hex(random_bytes(5)); $entity=$service->create('brand',$key,'Integration Brand',['description'=>'db']); self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/',$entity->canonicalId); self::assertSame($entity->canonicalId,$service->create('brand',$key,'Integration Brand',['description'=>'db'])->canonicalId); $renamed=$service->rename($entity->canonicalId,'Integration Brand Renamed',1); self::assertSame($entity->canonicalId,$renamed->canonicalId); self::assertSame(2,$renamed->revision); $this->expectException(AuthorityRevisionConflict::class); $service->retire($entity->canonicalId,1); }

    public function test_authority_lifecycle_filter_and_stale_revision_are_db_backed(): void {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, ['description']));
        $service = new AuthorityService(new WpdbAuthorityRepository(), $types);
        $key = 'lifecycle-' . bin2hex(random_bytes(5));
        $entity = $service->create('brand', $key, 'Lifecycle Brand', ['description' => 'before']);
        self::assertSame($entity->canonicalId, $service->create('brand', $key, 'Lifecycle Brand', ['description' => 'before'])->canonicalId);
        $renamed = $service->rename($entity->canonicalId, 'Lifecycle Brand Renamed', 1);
        self::assertSame(2, $renamed->revision);
        self::assertSame(2, $service->rename($entity->canonicalId, 'Lifecycle Brand Renamed', 2)->revision);
        $retired = $service->retire($entity->canonicalId, 2);
        self::assertSame(3, $retired->revision);
        self::assertCount(0, array_filter($service->list('brand'), static fn ($item): bool => $item->canonicalId === $entity->canonicalId));
        self::assertCount(1, array_filter($service->list('brand', true), static fn ($item): bool => $item->canonicalId === $entity->canonicalId));
        $active = $service->reactivate($entity->canonicalId, 3);
        self::assertSame(4, $active->revision);
        try {
            $service->rename($entity->canonicalId, 'Stale Write', 3);
            self::fail('Expected stale revision conflict.');
        } catch (AuthorityRevisionConflict) {
            $current = array_values(array_filter($service->list('brand'), static fn ($item): bool => $item->canonicalId === $entity->canonicalId));
            self::assertSame('Lifecycle Brand Renamed', $current[0]->canonicalName);
        }
    }

    public function test_authority_endpoint_resolver_and_graph_vertical_slice_preserve_identity(): void {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, ['description']));
        $authority = new AuthorityService(new WpdbAuthorityRepository(), $types);
        $brand = $authority->create('brand', 'vertical-' . bin2hex(random_bytes(5)), 'Vertical Brand');
        $resolver = new \NHK\Core\Infrastructure\Graph\AuthorityEndpointResolver($types, new WpdbAuthorityRepository());
        $brandRef = new NodeReference('brand', $brand->canonicalId);
        self::assertTrue($resolver->supports('brand'));
        self::assertTrue($resolver->exists($brandRef));
        $this->expectException(\NHK\Core\Graph\Exception\InvalidEndpointReference::class);
        $resolver->normalize(new NodeReference('brand', 'not-a-uuid'));
    }

    public function test_graph_authority_edge_survives_rename_retire_reactivate_and_paginates(): void {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, ['description']));
        $authority = new AuthorityService(new WpdbAuthorityRepository(), $types);
        $brand = $authority->create('brand', 'graph-' . bin2hex(random_bytes(5)), 'Graph Brand');
        $postId = wp_insert_post(['post_title' => 'P3 vertical slice', 'post_status' => 'draft']);
        self::assertGreaterThan(0, $postId);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new WpPostEndpointResolver());
        $endpoints->register('brand', new \NHK\Core\Infrastructure\Graph\AuthorityEndpointResolver($types, new WpdbAuthorityRepository()));
        $service = new GraphService(new WpdbGraphRepository($GLOBALS['wpdb']), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $source = new NodeReference('wp_post', get_current_blog_id() . ':' . $postId);
        $target = new NodeReference('brand', $brand->canonicalId);
        $edge = $service->create($source, 'about', $target);
        self::assertCount(1, $service->findOutgoing($source)['items']);
        self::assertSame(1, $service->findIncoming($target)['items'][0]->revision);
        $authority->rename($brand->canonicalId, 'Graph Brand Renamed', 1);
        $authority->retire($brand->canonicalId, 2);
        self::assertSame($brand->canonicalId, $service->findIncoming($target, null, 0, 1, true)['items'][0]->target->reference->endpoint_key);
        $authority->reactivate($brand->canonicalId, 3);
        $retired = $service->retire($edge->edge_uuid, 1);
        self::assertCount(0, $service->findOutgoing($source)['items']);
        self::assertCount(1, $service->findOutgoing($source, null, 0, 1, true)['items']);
        $reactivated = $service->reactivate($edge->edge_uuid, $retired->revision);
        self::assertSame($edge->edge_uuid, $reactivated->edge_uuid);
        self::assertSame(3, $reactivated->revision);
        wp_delete_post($postId, true);
    }

    public function test_graph_cursor_pagination_and_retired_filter_are_db_backed(): void {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $authority = new AuthorityService(new WpdbAuthorityRepository(), $types);
        $postId = wp_insert_post(['post_title' => 'P3 pagination fixture', 'post_status' => 'draft']);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new WpPostEndpointResolver());
        $endpoints->register('brand', new \NHK\Core\Infrastructure\Graph\AuthorityEndpointResolver($types, new WpdbAuthorityRepository()));
        $service = new GraphService(new WpdbGraphRepository($GLOBALS['wpdb']), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $source = new NodeReference('wp_post', get_current_blog_id() . ':' . $postId);
        foreach (range(1, 3) as $number) {
            $brand = $authority->create('brand', 'page-' . bin2hex(random_bytes(5)), 'Page Brand ' . $number);
            $service->create($source, 'about', new NodeReference('brand', $brand->canonicalId));
        }
        $pageOne = $service->findOutgoing($source, 'about', 0, 2);
        self::assertCount(2, $pageOne['items']);
        self::assertIsInt($pageOne['next_cursor']);
        $pageTwo = $service->findOutgoing($source, 'about', $pageOne['next_cursor'], 2);
        self::assertCount(1, $pageTwo['items']);
        self::assertNull($pageTwo['next_cursor']);
        $service->retire($pageOne['items'][0]->edge_uuid, $pageOne['items'][0]->revision);
        self::assertCount(2, $service->findOutgoing($source, 'about', 0, 10)['items']);
        self::assertCount(3, $service->findOutgoing($source, 'about', 0, 10, true)['items']);
        wp_delete_post($postId, true);
    }
}
