<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry,FakeEndpointResolver,NodeReference,PredicateDefinition,PredicateRegistry};
use NHK\Core\Graph\Exception\{InvalidRelationTargetType,RelationCardinalityViolation,RelationRevisionConflict,RelationAlreadyRetired};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class GraphCoreContractTest extends TestCase {
    private function service(?PredicateRegistry $predicates=null): array { $repo=new InMemoryGraphRepository();$types=new EndpointTypeRegistry();foreach(['wp_post','brand','media','knowledge'] as $type)$types->register($type,new FakeEndpointResolver($type,['1:1','1:2','a','b']));$audit=new InMemoryAuditSink();return [new GraphService($repo,$types,$predicates??new PredicateRegistry(),$audit),$repo,$audit]; }
    public function test_uuid_v7_and_legacy_uuid_round_trip(): void { $v7=UuidCodec::newV7();self::assertSame($v7,UuidCodec::fromBinary(UuidCodec::toBinary($v7)));$v4='550e8400-e29b-41d4-a716-446655440000';self::assertSame($v4,UuidCodec::fromBinary(UuidCodec::toBinary($v4)));self::assertSame('7',explode('-',$v7)[2][0]); }
    public function test_node_reference_and_resolution_are_idempotent(): void { [$service,$repo]= $this->service();$a=new NodeReference('brand','A');$b=new NodeReference('brand','a');$e=$service->create($a,'about',new NodeReference('brand','b'));self::assertSame($e->source->internal_node_id,$repo->resolveNode(new NodeReference('brand','a'))->internal_node_id);self::assertSame(1,count($service->findOutgoing(new NodeReference('brand','a'))['items'])); }
    public function test_unknown_endpoint_and_predicate_are_rejected(): void { [$service]= $this->service();$this->expectException(\NHK\Core\Graph\Exception\UnsupportedEndpointType::class);$service->create(new NodeReference('unknown','a'),'about',new NodeReference('brand','b')); }
    public function test_create_is_idempotent_and_audited(): void { [$service,$repo,$audit]=$this->service();$a=new NodeReference('brand','a');$b=new NodeReference('brand','b');$one=$service->create($a,'about',$b);$two=$service->create($a,'about',$b);self::assertSame($one->edge_uuid,$two->edge_uuid);self::assertCount(2,$audit->events); }
    public function test_forward_reverse_retire_reactivate_and_no_resurrection(): void { [$service]= $this->service();$a=new NodeReference('brand','a');$b=new NodeReference('brand','b');$edge=$service->create($a,'about',$b);self::assertCount(1,$service->findIncoming($b)['items']);$retired=$service->retire($edge->edge_uuid,1);self::assertCount(0,$service->findOutgoing($a)['items']);self::assertCount(1,$service->findOutgoing($a,null,0,50,true)['items']);$this->expectException(RelationAlreadyRetired::class);$service->create($a,'about',$b); }
    public function test_reactivate_requires_revision_and_explicit_operation(): void { [$service]= $this->service();$edge=$service->create(new NodeReference('brand','a'),'about',new NodeReference('brand','b'));$service->retire($edge->edge_uuid,1);$this->expectException(RelationRevisionConflict::class);$service->reactivate($edge->edge_uuid,1); }
    public function test_self_relation_and_cardinality_fail(): void { [$service]= $this->service();$this->expectException(InvalidRelationTargetType::class);$service->create(new NodeReference('brand','a'),'about',new NodeReference('brand','a')); }
    public function test_outbound_and_inbound_one_cardinality_fail(): void { $p=new PredicateRegistry();$p->register(new PredicateDefinition('test.one',['brand'],['knowledge'],'ONE','ONE'));[$service]= $this->service($p);$service->create(new NodeReference('brand','a'),'test.one',new NodeReference('knowledge','1:1'));$this->expectException(RelationCardinalityViolation::class);$service->create(new NodeReference('brand','a'),'test.one',new NodeReference('knowledge','1:2')); }
    public function test_audit_sink_receives_all_mutations(): void { [$service,, $audit]=$this->service();$edge=$service->create(new NodeReference('brand','a'),'about',new NodeReference('brand','b'));$retired=$service->retire($edge->edge_uuid,1);$service->reactivate($retired->edge_uuid,2);self::assertSame(['RelationCreated','RelationRetired','RelationReactivated'],array_column($audit->events,'event')); }
}
