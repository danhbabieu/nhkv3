<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry,FakeEndpointResolver,NodeReference,PredicateDefinition,PredicateRegistry};
use NHK\Core\Graph\Exception\{EndpointNotFound,GraphNodeInUse,InvalidRelationSourceType,InvalidRelationTargetType,RelationCardinalityViolation};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;
final class P2AcceptanceGapTest extends TestCase {
    private function make(?PredicateRegistry $predicates=null): array { $repo=new InMemoryGraphRepository();$types=new EndpointTypeRegistry();foreach(['wp_post','brand','knowledge'] as $type)$types->register($type,new FakeEndpointResolver($type,['1','2','a','b','c']));return [new GraphService($repo,$types,$predicates??new PredicateRegistry(),new InMemoryAuditSink()),$repo]; }
    public function test_missing_endpoint_is_rejected(): void { [$service]=$this->make();$this->expectException(EndpointNotFound::class);$service->create(new NodeReference('brand','missing'),'about',new NodeReference('brand','b')); }
    public function test_unknown_predicate_is_rejected(): void { [$service]=$this->make();$this->expectException(\NHK\Core\Graph\Exception\UnknownPredicate::class);$service->create(new NodeReference('brand','a'),'unknown',new NodeReference('brand','b')); }
    public function test_source_and_target_type_validation_are_separate(): void { $p=new PredicateRegistry();$p->register(new PredicateDefinition('brand.only',['brand'],['knowledge']));[$service]=$this->make($p);$this->expectException(InvalidRelationSourceType::class);$service->create(new NodeReference('knowledge','a'),'brand.only',new NodeReference('knowledge','b')); }
    public function test_target_type_validation_is_rejected(): void { $p=new PredicateRegistry();$p->register(new PredicateDefinition('brand.only',['brand'],['knowledge']));[$service]=$this->make($p);$this->expectException(InvalidRelationTargetType::class);$service->create(new NodeReference('brand','a'),'brand.only',new NodeReference('brand','b')); }
    public function test_uuid_lookup_cursor_and_node_in_use_guard(): void { [$service,$repo]=$this->make();$source=new NodeReference('brand','a');foreach(['1','2','c'] as $target)$service->create($source,'about',new NodeReference('wp_post',$target));$page=$service->findOutgoing($source,null,0,2);self::assertCount(2,$page['items']);self::assertNotNull($page['next_cursor']);$next=$service->findOutgoing($source,null,$page['next_cursor'],2);self::assertCount(1,$next['items']);self::assertNotNull($repo->findByUuid($page['items'][0]->edge_uuid));$node=$repo->resolveNode($source);$this->expectException(GraphNodeInUse::class);$repo->deleteNode($node); }
    public function test_inbound_one_cardinality_is_enforced(): void { $p=new PredicateRegistry();$p->register(new PredicateDefinition('inbound.one',['brand'],['knowledge'],'MANY','ONE'));[$service]=$this->make($p);$target=new NodeReference('knowledge','a');$service->create(new NodeReference('brand','1'),'inbound.one',$target);$this->expectException(RelationCardinalityViolation::class);$service->create(new NodeReference('brand','2'),'inbound.one',$target); }
    public function test_wp_post_key_is_not_permalink_identity(): void { [$service]=$this->make();$edge=$service->create(new NodeReference('wp_post','1'),'about',new NodeReference('brand','a'));self::assertSame('wp_post:1',$edge->source->reference->key()); }
}
