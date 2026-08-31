<?php
declare(strict_types=1);
namespace NHK\Tests\Integration;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\{ControlledApplyService,GovernanceService};
use NHK\Core\Contracts\Governance\ApplyExecutionHook;
use NHK\Core\Domain\Authority\{EntityTypeDefinition,EntityTypeRegistry};
use NHK\Core\Domain\Governance\{Proposal,ProposalState};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Infrastructure\Governance\{NoOpApplyExecutionHook,WpdbApplyAttemptRepository,WpdbAuditSink,WpdbProposalRepository};
use NHK\Core\Infrastructure\Migration\GovernanceMigration003;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class P4ControlledApplyIntegrationTest extends TestCase
{
    protected function setUp():void { if(getenv('NHK_WP_TEST_PATH')===false)self::markTestSkipped('Set NHK_WP_TEST_PATH=public.'); require_once rtrim((string)getenv('NHK_WP_TEST_PATH'),'/').'/wp-load.php'; TestDatabaseGuard::selectTestDatabase(); TestDatabaseGuard::requireTestDatabase(); (new GovernanceMigration003())->up(); }

    public function test_failure_rolls_back_authority_persists_failed_attempt_and_retry_is_successful():void
    {
        global $wpdb; $types=new EntityTypeRegistry();$types->register(new EntityTypeDefinition('brand',1,true,[]));$authority=new AuthorityService(new WpdbAuthorityRepository($wpdb),$types);
        $entity=$authority->create('brand','p4-'.UuidCodec::newV7(),'P4 Original'); $repo=new WpdbProposalRepository($wpdb);$proposal=new Proposal(UuidCodec::newV7(),$entity->canonicalId,'rename',['name'=>'P4 Changed'],str_repeat('d',64),1,'deps',ProposalState::DRAFT,'1',null,null,'p4-'.UuidCodec::newV7(),1,null,null,$entity->canonicalId,'brand');$governance=new GovernanceService($repo,new WpdbAuditSink(),new WpdbTransactionManager());$proposal=$governance->create($proposal);$proposal=$governance->approve($proposal->id,$proposal->contentFingerprint,$proposal->dependencyFingerprint,'2');
        $hook=new class implements ApplyExecutionHook { public function afterAttemptStarted():void{} public function afterAuthorityMutation():void{throw new \RuntimeException('INJECTED_AFTER_AUTHORITY');} public function beforeProposalApplied():void{} public function beforeCommit():void{} };
        $attempts=new WpdbApplyAttemptRepository($wpdb);$failing=new ControlledApplyService($repo,$attempts,new WpdbTransactionManager(),fn(Proposal $p)=>$authority->rename($p->targetUuid??$p->subjectId,'P4 Changed',1),new WpdbAuditSink(),null,$hook);
        try{$failing->apply($proposal->id);self::fail('failure hook did not throw');}catch(\RuntimeException $e){self::assertSame('INJECTED_AFTER_AUTHORITY',$e->getMessage());}
        self::assertSame('P4 Original',(new WpdbAuthorityRepository($wpdb))->findByCanonicalId($entity->canonicalId)?->canonicalName);self::assertSame(ProposalState::APPROVED,$repo->find($proposal->id)?->state);self::assertSame('failed',$attempts->findByProposal($proposal->id)[0]->state);
        $retry=new ControlledApplyService($repo,$attempts,new WpdbTransactionManager(),fn(Proposal $p)=>$authority->rename($p->targetUuid??$p->subjectId,'P4 Changed',1),new WpdbAuditSink(),null,new NoOpApplyExecutionHook());$result=$retry->apply($proposal->id);self::assertSame(2,$result['attempt_no']);self::assertSame(ProposalState::APPLIED,$repo->find($proposal->id)?->state);self::assertCount(2,$attempts->findByProposal($proposal->id));self::assertSame(2,(new WpdbAuthorityRepository($wpdb))->findByCanonicalId($entity->canonicalId)?->revision);
    }
}
