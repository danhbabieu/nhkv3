<?php
declare(strict_types=1);
namespace NHK\Core\Application\Dictionary;
use NHK\Core\Contracts\Dictionary\DictionaryLexicalRelationRepository;
use NHK\Core\Contracts\Shared\TransactionManager;
use NHK\Core\Domain\Dictionary\DictionaryLexicalRelation as R;
use NHK\Core\Shared\Uuid\UuidCodec;
final class DictionaryLexicalRelationGovernanceAdapter
{
    public function __construct(private DictionaryLexicalRelationRepository $repository, private $entryState, private $senseBelongs, private ?TransactionManager $transactions = null, private $invalidate = null) {}
    public function preview(array $input):array
    {
        $source=(string)($input['source_entry_uuid']??'');$target=(string)($input['target_entry_uuid']??'');$ss=$input['source_sense_uuid']??null;$ts=$input['target_sense_uuid']??null;$kind=strtoupper(trim((string)($input['kind']??'')));$base=['status'=>'BLOCKED','operation'=>strtoupper((string)($input['operation']??'ADD')),'blockers'=>[],'kind'=>$kind,'source_entry_uuid'=>$source,'target_entry_uuid'=>$target];
        try{DictionaryLexicalRelationPolicy::assertPair($source,$ss,$target,$ts,$kind);}catch(\Throwable $e){return $this->blocked($base,$e->getMessage());}
        foreach([[$source,'OWNER_NOT_FOUND'],[$target,'OWNER_NOT_FOUND']] as [$id,$code]){$state=($this->entryState)($id);if(!is_array($state)||($state['active']??false)!==true)return $this->blocked($base,$code);}
        if($ss!==null&&!($this->senseBelongs)($source,(string)$ss)||$ts!==null&&!($this->senseBelongs)($target,(string)$ts))return $this->blocked($base,'SENSE_ENTRY_MISMATCH');
        $operation = $base['operation'];
        if (in_array($operation, ['REPLACE','RETIRE','REACTIVATE'], true) && ((string) ($input['relation_uuid'] ?? '') === '' || (int) ($input['expected_revision'] ?? 0) < 1)) return $this->blocked($base, 'EXACT_RELATION_AND_REVISION_REQUIRED');
        $plan=['operation'=>$operation,'source_entry_uuid'=>$source,'source_sense_uuid'=>$ss,'target_entry_uuid'=>$target,'target_sense_uuid'=>$ts,'kind'=>$kind,'provenance'=>(array)($input['provenance']??[]),'relation_uuid'=>$input['relation_uuid']??null,'expected_revision'=>$input['expected_revision']??null,'idempotency_key'=>(string)($input['idempotency_key']??'')];$base['plan']=$plan;$base['plan_fingerprint']=hash('sha256',json_encode($plan,JSON_UNESCAPED_SLASHES));$base['status']='READY';return $base;
    }
    public function read(array $input = []): array
    {
        $relation = null;
        if (trim((string) ($input['relation_uuid'] ?? '')) !== '') $relation = $this->repository->findByUuid((string) $input['relation_uuid']);
        if ($relation === null && trim((string) ($input['idempotency_key'] ?? '')) !== '') $relation = $this->repository->findByIdempotencyKey((string) $input['idempotency_key']);
        return $relation === null ? ['status' => 'not_found', 'reason' => 'DICTIONARY_LEXICAL_RELATION_NOT_FOUND'] : ['status' => 'available', 'relation' => $relation->toArray()];
    }
    public function apply(array $plan,string $approvedFingerprint,string $idempotencyKey):array{if(!hash_equals($this->fingerprint($plan),$approvedFingerprint))return['status'=>'REPLAN_REQUIRED','blockers'=>['APPROVAL_FINGERPRINT_MISMATCH']];if($idempotencyKey!==($plan['idempotency_key']??''))return['status'=>'BLOCKED','blockers'=>['IDEMPOTENCY_KEY_MISMATCH']];try{$existing=$this->repository->findByIdempotencyKey($idempotencyKey);if($existing!==null)return['status'=>'READ_BACK_VERIFIED','relation'=>$this->canonicalRead($existing->relationUuid)->toArray(),'idempotent_replay'=>true];$apply=function()use($plan,$idempotencyKey):array{$op=(string)$plan['operation'];if($op==='ADD'){$relation=R::create(UuidCodec::newV7(),(string)$plan['source_entry_uuid'],$plan['source_sense_uuid']??null,(string)$plan['target_entry_uuid'],$plan['target_sense_uuid']??null,(string)$plan['kind'],(array)($plan['provenance']??[]),$idempotencyKey);$saved=$this->repository->create($relation);$read=$this->canonicalRead($saved->relationUuid);return['status'=>'READ_BACK_VERIFIED','relation'=>$read->toArray()];}$relation=$this->repository->findByUuid((string)($plan['relation_uuid']??''));if($relation===null)return['status'=>'FAILED_FINAL','blockers'=>['RELATION_NOT_FOUND']];$expected=(int)($plan['expected_revision']??0);if($expected<1)return['status'=>'REPLAN_REQUIRED','blockers'=>['STALE_RELATION_REVISION']];if($op==='REPLACE')$relation=new R($relation->relationUuid,$relation->sourceEntryUuid,$relation->sourceSenseUuid,$relation->targetEntryUuid,$relation->targetSenseUuid,$relation->kind,(array)($plan['provenance']??[]),$relation->idempotencyKey,$relation->state,$relation->revision,$relation->createdAt,$relation->updatedAt,$relation->retiredAt);$saved=match($op){'RETIRE'=>$this->repository->retire($relation,$expected),'REACTIVATE'=>$this->repository->reactivate($relation,$expected),'REPLACE'=>$this->repository->update($relation,$expected),default=>throw new \InvalidArgumentException('OPERATION_NOT_ALLOWED')};$read=$this->canonicalRead($saved->relationUuid);return['status'=>'READ_BACK_VERIFIED','relation'=>$read->toArray()];};return $this->transactions!==null?$this->transactions->transactional($apply):$apply();}catch(\Throwable $error){return['status'=>'FAILED_FINAL','blockers'=>[$error->getMessage()]];}}
    private function canonicalRead(string $relationUuid): R { $relation=$this->repository->findByUuid($relationUuid); if($relation===null) throw new \RuntimeException('DICTIONARY_LEXICAL_RELATION_READ_BACK_FAILED'); return $relation; }
    private function fingerprint(array $plan):string{return hash('sha256',json_encode($plan,JSON_UNESCAPED_SLASHES));}
    private function blocked(array $base,string $code):array{$base['status']='BLOCKED';$base['blockers'][]=$code;return $base;}
}
