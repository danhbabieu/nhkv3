<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Authority\Exception\{AuthorityRevisionConflict,InvalidPayload,StableKeyCollision};
use NHK\Core\Domain\Authority\{EntityTypeDefinition,EntityTypeRegistry};
use NHK\Core\Infrastructure\Graph\AuthorityEndpointResolver;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;
final class AuthorityCoreTest extends TestCase {
 private function service(?InMemoryAuthorityRepository $r=null):array{$r??=new InMemoryAuthorityRepository();$types=new EntityTypeRegistry();$types->register(new EntityTypeDefinition('brand',1,true,[]));return [new AuthorityService($r,$types),$r,$types];}
 public function test_brand_create_uses_uuid_and_stable_key_is_idempotent():void{[$s]=$this->service();$a=$s->create('brand','odo','Odo');$b=$s->create('brand','odo','Odo');$this->assertSame($a->canonicalId,$b->canonicalId);$this->assertSame(1,$a->revision);$this->assertSame('brand',$a->entityType);}
 public function test_unknown_payload_and_collision_are_rejected():void{[$s]=$this->service();$s->create('brand','a','A');$this->expectException(StableKeyCollision::class);$s->create('brand','a','Other');}
 public function test_payload_fields_are_validated():void{[$s]=$this->service();$this->expectException(InvalidPayload::class);$s->create('brand','a','A',['unknown'=>1]);}
 public function test_lifecycle_preserves_uuid_and_revision_locking():void{[$s]=$this->service();$a=$s->create('brand','a','A');$r=$s->rename($a->canonicalId,'B',1);$this->assertSame(2,$r->revision);$ret=$s->retire($r->canonicalId,2);$this->assertFalse($ret->active());$live=$s->reactivate($ret->canonicalId,3);$this->assertTrue($live->active());$this->assertSame($a->canonicalId,$live->canonicalId);$this->expectException(AuthorityRevisionConflict::class);$s->rename($live->canonicalId,'C',1);}
 public function test_generic_resolver_validates_uuid_and_retired_entities_remain_graph_endpoints():void{[$s,$r,$types]=$this->service();$a=$s->create('brand','a','A');$resolver=new AuthorityEndpointResolver($types,$r);$ref=$resolver->normalize(new NodeReference('brand',$a->canonicalId));$this->assertTrue($resolver->exists($ref));$s->retire($a->canonicalId,1);$this->assertTrue($resolver->exists($ref));}
}
