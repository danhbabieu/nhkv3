<?php
declare(strict_types=1);
namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\{ApplyAttemptRepository,ApplyExecutionHook,GovernanceAuditSink,GovernanceAuthorizer,ProposalRepository};
use NHK\Core\Contracts\Shared\TransactionManager;
use NHK\Core\Domain\Governance\{ApplyAttempt,ProposalState};
use NHK\Core\Governance\Exception\{InvalidProposalTransition,ProposalNotFound};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Transaction owner for governed authority mutations. The executor must use the same wpdb connection. */
final class ControlledApplyService
{
    public function __construct(private ProposalRepository $proposals, private ApplyAttemptRepository $attempts, private TransactionManager $transactions, private $executor, private ?GovernanceAuditSink $audit=null, private ?ProposalEligibilityService $eligibility=null, private ?ApplyExecutionHook $hook=null, private ?GovernanceAuthorizer $authorizer=null) {}

    /** @return array{proposal_id:string,attempt_no:int,result_entity_uuid:?string,idempotent:bool} */
    public function apply(string $proposalId): array
    {
        $this->authorizer?->require('nhk_apply_proposals');
        $started = gmdate('Y-m-d H:i:s.u');
        try {
            return $this->transactions->transactional(function() use ($proposalId,$started):array {
                $proposal=$this->proposals->findForUpdate($proposalId)??throw new ProposalNotFound('Proposal not found.');
                if($proposal->state===ProposalState::APPLIED){$success=$this->attempts->findSuccessful($proposalId);return ['proposal_id'=>$proposalId,'attempt_no'=>$success?->number??0,'result_entity_uuid'=>$success?->resultEntityUuid,'idempotent'=>true];}
                if($proposal->state!==ProposalState::APPROVED)throw new InvalidProposalTransition('Only approved proposals can be applied.');
                if($this->eligibility && !($this->eligibility->check($proposalId))->ready)throw new InvalidProposalTransition('Proposal is not eligible for apply.');
                $attempt=new ApplyAttempt(UuidCodec::newV7(),$proposalId,$this->attempts->nextAttemptNumberLocked($proposalId),'running',null,null,null,$started);
                $this->attempts->createRunning($attempt); $this->hook?->afterAttemptStarted(); $this->auditEvent('ApplyStarted',$proposalId,$proposal->actor!==null?(int)$proposal->actor:null,['attempt_no'=>$attempt->number]);
                $result=($this->executor)($proposal); $resultId=is_string($result)?$result:(is_object($result)&&property_exists($result,'canonicalId')?$result->canonicalId:null);
                $this->hook?->afterAuthorityMutation(); $this->attempts->markSucceeded($attempt->id,$resultId); $this->hook?->beforeProposalApplied(); $this->proposals->save($proposal->transition(ProposalState::APPLIED,$proposal->decisionActor,gmdate('Y-m-d H:i:s.u'))); $this->auditEvent('ApplySucceeded',$proposalId,$proposal->actor!==null?(int)$proposal->actor:null,['attempt_no'=>$attempt->number,'result_entity_uuid'=>$resultId]); $this->hook?->beforeCommit();
                return ['proposal_id'=>$proposalId,'attempt_no'=>$attempt->number,'result_entity_uuid'=>$resultId,'idempotent'=>false];
            });
        } catch(\Throwable $error) {
            // The semantic transaction has rolled back. Failure history is deliberately durable in a new transaction.
            try{$this->transactions->transactional(function()use($proposalId,$started,$error):void{
                // The proposal row remains the serialization point for the
                // durable attempt history, including the post-rollback write.
                $this->proposals->findForUpdate($proposalId)??throw new ProposalNotFound('Proposal not found.');
                $n=$this->attempts->nextAttemptNumberLocked($proposalId);
                $a=new ApplyAttempt(UuidCodec::newV7(),$proposalId,$n,'failed',null,substr((string)$error->getCode(),0,64),substr($error->getMessage(),0,2000),$started,gmdate('Y-m-d H:i:s.u'));
                $this->attempts->persistFailed($a);$this->auditEvent('ApplyFailed',$proposalId,null,['attempt_no'=>$n,'error_code'=>$a->errorCode]);
            });}catch(\Throwable $failure){$error->addSuppressed($failure);}
            throw $error;
        }
    }
    private function auditEvent(string $event,string $id,?int $actor,array $context):void { $this->audit?->recordEvent($event,'proposal',$id,$actor,$context); }
}
