<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Graph\AuditSink;
use NHK\Core\Domain\Authority\{EntityTypeDefinition,EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry,FakeEndpointResolver,NodeReference,PredicateRegistry};
use NHK\Core\Infrastructure\Graph\AuthorityEndpointResolver;
use NHK\Tests\Support\{InMemoryAuthorityRepository,InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;
final class AuthorityGraphIntegrationTest extends TestCase {
 public function test_post_about_brand_survives_rename_retire_reactivate():void{$authRepo=new InMemoryAuthorityRepository();$types=new EntityTypeRegistry();$types->register(new EntityTypeDefinition('brand',1,true,[]));$authority=new AuthorityService($authRepo,$types);$brand=$authority->create('brand','odo','Odo');$endpoints=new EndpointTypeRegistry();$endpoints->register('wp_post',new FakeEndpointResolver('wp_post',['1:42']));$endpoints->register('brand',new AuthorityEndpointResolver($types,$authRepo));$graph=new GraphService(new InMemoryGraphRepository(),$endpoints,new PredicateRegistry(),new class implements AuditSink{public function record(string $event,\NHK\Core\Domain\Graph\GraphEdge $edge):void{}});$post=new NodeReference('wp_post','1:42');$ref=new NodeReference('brand',$brand->canonicalId);$edge=$graph->create($post,'about',$ref);$renamed=$authority->rename($brand->canonicalId,'Odo Watches',1);$this->assertSame($brand->canonicalId,$renamed->canonicalId);$authority->retire($brand->canonicalId,2);$this->assertNotNull($graph->findEdge($post,'about',$ref));$authority->reactivate($brand->canonicalId,3);$same=$graph->findEdge($post,'about',new NodeReference('brand',$brand->canonicalId));$this->assertSame($edge->edge_uuid,$same?->edge_uuid);}
}
