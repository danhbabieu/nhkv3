<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Governance;
use NHK\Core\Contracts\Governance\ApplyAttemptRepository;
use NHK\Core\Domain\Governance\ApplyAttempt;
use NHK\Core\Shared\Uuid\UuidCodec;
final class WpdbApplyAttemptRepository implements ApplyAttemptRepository
{
    public function __construct(private ?object $database=null){}
    private function db():object{global $wpdb;return $this->database??$wpdb;}
    private function table():string{return $this->db()->prefix.'nhk_apply_attempts';}
    private function row(?array $r):?ApplyAttempt{if(!$r)return null; $states=['pending','running','succeeded','failed']; return new ApplyAttempt(UuidCodec::fromBinary($r['attempt_uuid']),UuidCodec::fromBinary($r['proposal_uuid']??$r['_proposal_uuid']), (int)$r['attempt_no'],$states[max(0,(int)$r['state']-1)]??'pending',!empty($r['result_entity_uuid'])?UuidCodec::fromBinary($r['result_entity_uuid']):null,$r['error_code']??null,$r['error_message']??null,$r['started_at']??null,$r['finished_at']??null);}
    private function proposalDbId(string $id):int{ $v=$this->db()->get_var($this->db()->prepare('SELECT id FROM '.$this->db()->prefix.'nhk_proposals WHERE proposal_uuid=%s',UuidCodec::toBinary($id))); if(!$v)throw new \RuntimeException('PROPOSAL_NOT_FOUND'); return (int)$v; }
    public function nextAttemptNumberLocked(string $proposalId):int{$id=$this->proposalDbId($proposalId);return 1+(int)$this->db()->get_var($this->db()->prepare('SELECT COALESCE(MAX(attempt_no),0) FROM '.$this->table().' WHERE proposal_id=%d',$id));}
    public function createRunning(ApplyAttempt $a):ApplyAttempt{$db=$this->db();$pid=$this->proposalDbId($a->proposalId);$ok=$db->query($db->prepare('INSERT INTO '.$this->table().' (attempt_uuid,proposal_id,attempt_no,state,started_at) VALUES (%s,%d,%d,2,%s)',UuidCodec::toBinary($a->id),$pid,$a->number,$a->startedAt??gmdate('Y-m-d H:i:s.u')));if($ok===false)throw new \RuntimeException('APPLY_ATTEMPT_INSERT_FAILED: '.$db->last_error);return $a;}
    public function markSucceeded(string $id,?string $result):ApplyAttempt{$db=$this->db();$ok=$db->query($db->prepare('UPDATE '.$this->table().' SET state=3,result_entity_uuid=%s,finished_at=%s WHERE attempt_uuid=%s AND state=2',$result?UuidCodec::toBinary($result):null,gmdate('Y-m-d H:i:s.u'),UuidCodec::toBinary($id)));if($ok!==1)throw new \RuntimeException('APPLY_ATTEMPT_STATE_CONFLICT');return $this->find($id)??throw new \RuntimeException('APPLY_ATTEMPT_NOT_FOUND');}
    public function persistFailed(ApplyAttempt $a):ApplyAttempt{$db=$this->db();$pid=$this->proposalDbId($a->proposalId);$ok=$db->query($db->prepare('INSERT INTO '.$this->table().' (attempt_uuid,proposal_id,attempt_no,state,error_code,error_message,started_at,finished_at) VALUES (%s,%d,%d,4,%s,%s,%s,%s)',UuidCodec::toBinary($a->id),$pid,$a->number,$a->errorCode,substr((string)$a->errorMessage,0,2000),$a->startedAt??gmdate('Y-m-d H:i:s.u'),$a->finishedAt??gmdate('Y-m-d H:i:s.u')));if($ok===false)throw new \RuntimeException('FAILED_ATTEMPT_INSERT_FAILED: '.$db->last_error);return $a;}
    private function find(string $id):?ApplyAttempt{$db=$this->db();$r=$db->get_row($db->prepare('SELECT a.*,p.proposal_uuid FROM '.$this->table().' a JOIN '.$db->prefix.'nhk_proposals p ON p.id=a.proposal_id WHERE a.attempt_uuid=%s',UuidCodec::toBinary($id)),ARRAY_A);return $this->row($r);}
    public function findByProposal(string $id):array{$db=$this->db();$rows=$db->get_results($db->prepare('SELECT a.*,p.proposal_uuid FROM '.$this->table().' a JOIN '.$db->prefix.'nhk_proposals p ON p.id=a.proposal_id WHERE p.proposal_uuid=%s ORDER BY a.attempt_no',UuidCodec::toBinary($id)),ARRAY_A)?:[];return array_map(fn(array $r)=>$this->row($r),$rows);}
    public function findSuccessful(string $id):?ApplyAttempt
    {
        // A caller can reach this branch immediately after waiting on the
        // proposal lock. Use a locking/current read so REPEATABLE READ does
        // not return a snapshot taken before the winning attempt committed.
        $db=$this->db();
        $r=$db->get_row($db->prepare('SELECT a.*,p.proposal_uuid FROM '.$this->table().' a JOIN '.$db->prefix.'nhk_proposals p ON p.id=a.proposal_id WHERE p.proposal_uuid=%s AND a.state=3 ORDER BY a.attempt_no LIMIT 1 FOR UPDATE',UuidCodec::toBinary($id)),ARRAY_A);
        return $this->row($r);
    }
}
