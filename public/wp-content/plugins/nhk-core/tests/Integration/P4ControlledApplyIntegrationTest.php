<?php
declare(strict_types=1);
namespace NHK\Tests\Integration;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\{ControlledApplyService,GovernanceService};
use NHK\Core\Contracts\Governance\ApplyExecutionHook;
use NHK\Core\Domain\Authority\{EntityTypeDefinition,EntityTypeRegistry};
use NHK\Core\Domain\Governance\{ApplyAttempt,Proposal,ProposalState};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Infrastructure\Governance\{NoOpApplyExecutionHook,WpdbApplyAttemptRepository,WpdbAuditSink,WpdbProposalRepository};
use NHK\Core\Governance\Exception\ProposalIdempotencyConflict;
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
        $events = $wpdb->get_col($wpdb->prepare('SELECT event_type FROM '.$wpdb->prefix.'nhk_audit_events WHERE object_type=%s AND object_key=%s ORDER BY id', 'proposal', $proposal->id));
        self::assertSame(['ProposalCreated','ProposalApproved','ApplyFailed','ApplyStarted','ApplySucceeded'], $events);
    }

    public function test_true_concurrent_apply_serializes_on_proposal_row_and_returns_same_result(): void
    {
        if (!function_exists('pcntl_fork')) self::fail('pcntl_fork is required for true concurrency acceptance.');
        global $wpdb;
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $authority = new AuthorityService(new WpdbAuthorityRepository($wpdb), $types);
        $key = 'p4-concurrent-' . bin2hex(random_bytes(5));
        $entity = $authority->create('brand', $key, 'Before');
        $repo = new WpdbProposalRepository($wpdb);
        $proposal = new Proposal(UuidCodec::newV7(), $entity->canonicalId, 'rename', ['name' => 'After'], str_repeat('a', 64), 1, 'deps', ProposalState::DRAFT, '1', null, null, 'idem-' . $key, 1, null, null, $entity->canonicalId, 'brand');
        $governance = new GovernanceService($repo, new WpdbAuditSink(), new WpdbTransactionManager());
        $proposal = $governance->create($proposal);
        $proposal = $governance->approve($governance->submit($proposal->id)->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, '2');
        $dir = sys_get_temp_dir(); $barrier = tempnam($dir, 'nhk-barrier-'); $release = tempnam($dir, 'nhk-release-'); $files = [tempnam($dir, 'nhk-result-'), tempnam($dir, 'nhk-result-')];
        $pids = [];
        foreach ([true, false] as $index => $holder) {
            $pid = pcntl_fork(); if ($pid === -1) self::fail('Unable to fork apply worker.');
            if ($pid === 0) {
                global $wpdb;
                $wpdb = new \wpdb(DB_USER, DB_PASSWORD, 'nhk_v3_test', DB_HOST); $wpdb->set_prefix($GLOBALS['table_prefix']); $wpdb->suppress_errors(true);
                $hook = $holder ? new class($barrier, $release) implements ApplyExecutionHook { public function __construct(private string $barrier, private string $release) {} public function afterAttemptStarted(): void { file_put_contents($this->barrier, 'locked'); $deadline = microtime(true) + 10; while (!file_exists($this->release) && microtime(true) < $deadline) usleep(10000); if (!file_exists($this->release)) throw new \RuntimeException('CONCURRENCY_BARRIER_TIMEOUT'); } public function afterAuthorityMutation(): void {} public function beforeProposalApplied(): void {} public function beforeCommit(): void {} } : new NoOpApplyExecutionHook();
                try { $result = (new ControlledApplyService(new WpdbProposalRepository(), new WpdbApplyAttemptRepository(), new WpdbTransactionManager(), fn(Proposal $p) => (new AuthorityService(new WpdbAuthorityRepository(), $types))->rename($p->targetUuid ?? $p->subjectId, 'After', 1), new WpdbAuditSink(), null, $hook))->apply($proposal->id); file_put_contents($files[$index], json_encode(['status' => 'ok', 'result' => $result])); } catch (\Throwable $e) { file_put_contents($files[$index], json_encode(['status' => 'error', 'error' => $e->getMessage()])); }
                exit(0);
            }
            $pids[] = $pid;
            if ($holder) { $deadline = microtime(true) + 10; while (!file_exists($barrier) && microtime(true) < $deadline) usleep(10000); if (!file_exists($barrier)) self::fail('Apply worker did not acquire proposal lock.'); }
        }
        usleep(100000); file_put_contents($release, 'release');
        foreach ($pids as $pid) { pcntl_waitpid($pid, $status); self::assertSame(0, pcntl_wexitstatus($status)); }
        $results = array_map(static fn(string $file): array => json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR), $files);
        self::assertCount(2, array_filter($results, static fn(array $r): bool => $r['status'] === 'ok'));
        self::assertSame($results[0]['result']['result_entity_uuid'], $results[1]['result']['result_entity_uuid'], json_encode($results));
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'nhk_entities WHERE entity_type=%s AND stable_key=%s', 'brand', $key)));
        self::assertSame(2, (int) $wpdb->get_var($wpdb->prepare('SELECT revision FROM '.$wpdb->prefix.'nhk_entities WHERE entity_type=%s AND stable_key=%s', 'brand', $key)));
        self::assertSame((int) array_sum(array_map(static fn(array $r): int => $r['result']['idempotent'] ? 0 : 1, $results)), 1);
        foreach ([$barrier, $release, ...$files] as $file) @unlink($file);
        $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'nhk_entities WHERE entity_type=%s AND stable_key=%s', 'brand', $key));
    }

    public function test_idempotency_race_has_one_row_and_conflicting_command_is_rejected(): void
    {
        if (!function_exists('pcntl_fork')) self::fail('pcntl_fork is required for idempotency race acceptance.');
        global $wpdb;
        $key = 'p4-idem-' . bin2hex(random_bytes(5)); $files = [tempnam(sys_get_temp_dir(), 'nhk-idem-'), tempnam(sys_get_temp_dir(), 'nhk-idem-')]; $pids = [];
        foreach (['Same', 'Same'] as $index => $name) {
            $pid = pcntl_fork(); if ($pid === 0) { $wpdb = new \wpdb(DB_USER, DB_PASSWORD, 'nhk_v3_test', DB_HOST); $wpdb->set_prefix($GLOBALS['table_prefix']); $wpdb->suppress_errors(true); $p = new Proposal(UuidCodec::newV7(), 'brand', 'rename', ['name'=>$name], str_repeat('b', 64), 1, 'deps', ProposalState::DRAFT, '1', null, null, $key, 1, null, null, null, 'brand'); try { $out=(new GovernanceService(new WpdbProposalRepository(), new WpdbAuditSink(), new WpdbTransactionManager()))->create($p); file_put_contents($files[$index], json_encode(['status'=>'ok','id'=>$out->id])); } catch (\Throwable $e) { file_put_contents($files[$index], json_encode(['status'=>'error','error'=>$e->getMessage()])); } exit(0); } $pids[]=$pid;
        }
        foreach ($pids as $pid) pcntl_waitpid($pid, $status);
        $same = array_map(static fn(string $f): array => json_decode((string) file_get_contents($f), true, 512, JSON_THROW_ON_ERROR), $files);
        self::assertCount(2, array_filter($same, static fn(array $r): bool => $r['status']==='ok'), json_encode($same)); self::assertSame($same[0]['id'], $same[1]['id']);
        $p = new Proposal(UuidCodec::newV7(), 'brand', 'rename', ['name'=>'Different'], str_repeat('c', 64), 1, 'deps', ProposalState::DRAFT, '1', null, null, $key, 1, null, null, null, 'brand');
        try { (new GovernanceService(new WpdbProposalRepository($wpdb)))->create($p); self::fail('Conflicting idempotency command was accepted.'); } catch (ProposalIdempotencyConflict) { self::assertTrue(true); }
        self::assertSame(1, (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'nhk_proposals WHERE idempotency_key=%s',$key)));
        foreach($files as $file) @unlink($file); $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'nhk_proposals WHERE idempotency_key=%s',$key));
    }

    public function test_apply_attempt_repository_omits_corrupt_rows(): void
    {
        global $wpdb;
        $proposal = new Proposal(UuidCodec::newV7(), 'corrupt-attempt', 'rename', ['name' => 'x'], str_repeat('1', 64), 1, 'deps', ProposalState::DRAFT, '1', null, null, 'corrupt-attempt-' . bin2hex(random_bytes(4)), 1, null, null, null, 'brand');
        $proposal = (new GovernanceService(new WpdbProposalRepository($wpdb), new WpdbAuditSink(), new WpdbTransactionManager()))->create($proposal);
        $repository = new WpdbApplyAttemptRepository($wpdb);
        $attempt = new ApplyAttempt(UuidCodec::newV7(), $proposal->id, 1, 'running');

        try {
            $repository->createRunning($attempt);
            $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'nhk_apply_attempts SET attempt_no=%d WHERE attempt_uuid=%s', 0, UuidCodec::toBinary($attempt->id)));
            self::assertSame([], $repository->findByProposal($proposal->id));
        } finally {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_apply_attempts WHERE attempt_uuid=%s', UuidCodec::toBinary($attempt->id)));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_proposals WHERE proposal_uuid=%s', UuidCodec::toBinary($proposal->id)));
        }
    }

    public function test_governance_repositories_omit_out_of_range_state_rows(): void
    {
        global $wpdb;
        $proposal = new Proposal(UuidCodec::newV7(), 'invalid-state', 'rename', ['name' => 'x'], str_repeat('2', 64), 1, 'deps', ProposalState::DRAFT, '1', null, null, 'invalid-state-' . bin2hex(random_bytes(4)), 1, null, null, null, 'brand');
        $proposalRepository = new WpdbProposalRepository($wpdb);
        $proposal = (new GovernanceService($proposalRepository, new WpdbAuditSink(), new WpdbTransactionManager()))->create($proposal);
        $attemptRepository = new WpdbApplyAttemptRepository($wpdb);
        $attempt = new ApplyAttempt(UuidCodec::newV7(), $proposal->id, 1, 'running');

        try {
            $attemptRepository->createRunning($attempt);
            $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'nhk_apply_attempts SET state=%d WHERE attempt_uuid=%s', 9, UuidCodec::toBinary($attempt->id)));
            $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'nhk_proposals SET state=%d WHERE proposal_uuid=%s', 99, UuidCodec::toBinary($proposal->id)));

            self::assertSame([], $attemptRepository->findByProposal($proposal->id));
            self::assertNull($proposalRepository->find($proposal->id));
        } finally {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_apply_attempts WHERE attempt_uuid=%s', UuidCodec::toBinary($attempt->id)));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_proposals WHERE proposal_uuid=%s', UuidCodec::toBinary($proposal->id)));
        }
    }

    public function test_proposal_repository_preflight_is_idempotent_and_rejects_changed_content(): void
    {
        global $wpdb;
        $key = 'p4-preflight-' . bin2hex(random_bytes(5));
        $proposal = new Proposal(UuidCodec::newV7(), 'brand', 'rename', ['name' => 'Preflight'], str_repeat('e', 64), 1, 'deps', ProposalState::DRAFT, '1', null, null, $key, 1, null, null, null, 'brand');
        $repository = new WpdbProposalRepository($wpdb);
        $repository->create($proposal);

        try {
            $same = $repository->create(new Proposal(UuidCodec::newV7(), $proposal->subjectId, $proposal->operation, $proposal->payload, $proposal->contentFingerprint, $proposal->expectedRevision, $proposal->dependencyFingerprint, $proposal->state, $proposal->actor, null, null, $key, 1, null, null, null, $proposal->entityType));
            self::assertSame($proposal->id, $same->id);

            $repository->create(new Proposal(UuidCodec::newV7(), 'brand', 'rename', ['name' => 'Changed'], str_repeat('f', 64), 1, 'deps', ProposalState::DRAFT, '1', null, null, $key, 1, null, null, null, 'brand'));
            self::fail('Expected changed proposal content to be rejected for an existing idempotency key.');
        } catch (ProposalIdempotencyConflict $exception) {
            self::assertSame('Idempotency key is already bound to different content.', $exception->getMessage());
        } finally {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_proposals WHERE idempotency_key=%s', $key));
        }
    }
}
