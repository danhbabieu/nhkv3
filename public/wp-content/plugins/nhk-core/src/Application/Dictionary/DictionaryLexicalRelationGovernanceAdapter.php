<?php
declare(strict_types=1);
namespace NHK\Core\Application\Dictionary;
use NHK\Core\Contracts\Dictionary\DictionaryLexicalRelationRepository;
use NHK\Core\Domain\Dictionary\DictionaryLexicalRelation as R;
use NHK\Core\Shared\Uuid\UuidCodec;
final class DictionaryLexicalRelationGovernanceAdapter
{
    public function __construct(private DictionaryLexicalRelationRepository $repository, private $entryState, private $senseBelongs) {}
    public function preview(array $input):array
    {
        $source=(string)($input['source_entry_uuid']??'');$target=(string)($input['target_entry_uuid']??'');$ss=$input['source_sense_uuid']??null;$ts=$input['target_sense_uuid']??null;$kind=strtoupper(trim((string)($input['kind']??'')));$base=['status'=>'BLOCKED','operation'=>strtoupper((string)($input['operation']??'ADD')),'blockers'=>[],'kind'=>$kind,'source_entry_uuid'=>$source,'target_entry_uuid'=>$target];
        try{DictionaryLexicalRelationPolicy::assertPair($source,$ss,$target,$ts,$kind);}catch(\Throwable $e){return $this->blocked($base,$e->getMessage());}
        foreach([[$source,'OWNER_NOT_FOUND'],[$target,'OWNER_NOT_FOUND']] as [$id,$code]){$state=($this->entryState)($id);if(!is_array($state)||($state['active']??false)!==true)return $this->blocked($base,$code);}
        if($ss!==null&&!($this->senseBelongs)($source,(string)$ss)||$ts!==null&&!($this->senseBelongs)($target,(string)$ts))return $this->blocked($base,'SENSE_ENTRY_MISMATCH');
        $plan=['operation'=>$base['operation'],'source_entry_uuid'=>$source,'source_sense_uuid'=>$ss,'target_entry_uuid'=>$target,'target_sense_uuid'=>$ts,'kind'=>$kind,'idempotency_key'=>(string)($input['idempotency_key']??'')];$base['plan']=$plan;$base['plan_fingerprint']=hash('sha256',json_encode($plan,JSON_UNESCAPED_SLASHES));$base['status']='READY';return $base;
    }
    public function apply(array $plan,string $approvedFingerprint,string $idempotencyKey):array{if(!hash_equals((string)($plan['plan_fingerprint']??''),$approvedFingerprint))return['status'=>'REPLAN_REQUIRED','blockers'=>['APPROVAL_FINGERPRINT_MISMATCH']];if($idempotencyKey!==($plan['idempotency_key']??''))return['status'=>'BLOCKED','blockers'=>['IDEMPOTENCY_KEY_MISMATCH']];$existing=$this->repository->findByIdempotencyKey($idempotencyKey);if($existing!==null)return['status'=>'READ_BACK_VERIFIED','relation'=>$existing->toArray(),'idempotent_replay'=>true];try{$op=(string)$plan['operation'];if($op==='ADD'){$relation=R::create(UuidCodec::newV7(),(string)$plan['source_entry_uuid'],$plan['source_sense_uuid']??null,(string)$plan['target_entry_uuid'],$plan['target_sense_uuid']??null,(string)$plan['kind'],(array)($plan['provenance']??[]),$idempotencyKey);$saved=$this->repository->create($relation);return['status'=>'READ_BACK_VERIFIED','relation'=>$this->repository->findByUuid($saved->relationUuid)?->toArray()??$saved->toArray()];}$relation=$this->repository->findByUuid((string)($plan['relation_uuid']??''));if($relation===null)return['status'=>'FAILED_FINAL','blockers'=>['RELATION_NOT_FOUND']];$expected=(int)($plan['expected_revision']??$relation->revision);$saved=match($op){'RETIRE'=>$this->repository->retire($relation,$expected),'REACTIVATE'=>$this->repository->reactivate($relation,$expected),'REPLACE'=>$this->repository->update($relation,$expected),default=>throw new \InvalidArgumentException('OPERATION_NOT_ALLOWED')};return['status'=>'READ_BACK_VERIFIED','relation'=>$this->repository->findByUuid($saved->relationUuid)?->toArray()??$saved->toArray()];}catch(\Throwable $error){return['status'=>'FAILED_FINAL','blockers'=>[$error->getMessage()]];}}
    private function blocked(array $base,string $code):array{$base['status']='BLOCKED';$base['blockers'][]=$code;return $base;}
}
