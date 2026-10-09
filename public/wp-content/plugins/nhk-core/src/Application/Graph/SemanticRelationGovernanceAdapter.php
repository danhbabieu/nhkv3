<?php
declare(strict_types=1);
namespace NHK\Core\Application\Graph;
use NHK\Core\Application\Graph\SemanticEnrichmentRelationRegistry;
use NHK\Core\Contracts\Shared\TransactionManager;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Domain\Graph\{NodeReference, PredicateRegistry};
final class SemanticRelationGovernanceAdapter
{
    public function __construct(private string $registryVersion, private string $registryHash, private $endpointState, private $relationState, private $graph = null, private $contexts = null, private ?TransactionManager $transactions = null, private $invalidate = null, private ?PredicateRegistry $predicates = null) {}
    public function preview(array $input): array
    {
        $operation = strtoupper(trim((string) ($input['operation'] ?? '')));
        $source = $this->locator($input['source'] ?? null); $target = $this->locator($input['target'] ?? null);
        $base = ['status'=>'BLOCKED','operation'=>$operation,'source'=>$source,'target'=>$target,'predicate'=>(string) ($input['predicate'] ?? ''),'registry_version'=>$this->registryVersion,'registry_hash'=>$this->registryHash,'blockers'=>[]];
        if (!in_array($operation,['ADD','REPLACE','RETIRE','REACTIVATE'],true)) return $this->blocked($base,'OPERATION_NOT_ALLOWED');
        if ($source === null || $target === null) return $this->blocked($base,'EXACT_ENDPOINTS_REQUIRED');
        $predicate = trim((string) ($input['predicate'] ?? ''));
        if ($predicate === '') return $this->blocked($base,'PREDICATE_NOT_ALLOWED');
        $registry = new SemanticEnrichmentRelationRegistry();
        if ($predicate === 'associated_with' && !$registry->allows($source['type'],$predicate,$target['type'])) return $this->blocked($base,'PREDICATE_NOT_ALLOWED');
        $sourceState = ($this->endpointState)($source['type'],$source['id']); $targetState = ($this->endpointState)($target['type'],$target['id']);
        foreach ([[$sourceState,'OWNER_NOT_FOUND'],[$targetState,'OWNER_NOT_FOUND']] as [$state,$code]) if (!is_array($state) || ($state['exists'] ?? true) === false) return $this->blocked($base,$code);
        if (($sourceState['active'] ?? false) !== true || ($targetState['active'] ?? false) !== true) return $this->blocked($base,'OWNER_INACTIVE');
        $context = (array) ($input['context'] ?? []);
        if (in_array($predicate, ['specimen_of', 'lists_specimen'], true) && in_array($operation, ['ADD', 'REPLACE'], true)) {
            $relationErrors = SpecimenProductRelationPolicy::validate([
                'predicate' => $predicate,
                'source_type' => $source['type'],
                'source_uuid' => $source['id'],
                'target_type' => $target['type'],
                'target_uuid' => $target['id'],
                'source_revision' => $input['source_revision'] ?? $sourceState['revision'] ?? 0,
                'target_revision' => $input['target_revision'] ?? $targetState['revision'] ?? 0,
                'scope_code' => $input['scope_code'] ?? ($context['scope_code'] ?? ''),
                'provenance' => $input['provenance'] ?? '',
                'evidence_refs' => $input['evidence_refs'] ?? [],
            ], $this->predicates);
            if ($relationErrors !== []) return $this->blocked($base, $relationErrors[0]);
        }
        if (trim((string) ($input['scope_code'] ?? $context['scope_code'] ?? '')) === '') return $this->blocked($base,'SCOPE_INVALID');
        $lifecycle = in_array($operation,['RETIRE','REACTIVATE'],true);
        $existingContext = null;
        if ($lifecycle) {
            if (trim((string) ($input['edge_uuid'] ?? '')) === '') return $this->blocked($base,'EXACT_EDGE_REQUIRED');
            if ((int) ($input['expected_edge_revision'] ?? 0) < 1) return $this->blocked($base,'STALE_EDGE_REVISION');
            if (!is_object($this->contexts) || !method_exists($this->contexts, 'findByEdgeUuid') || ($existingContext = $this->contexts->findByEdgeUuid((string) $input['edge_uuid'])) === null) return $this->blocked($base,'RELATION_CONTEXT_NOT_FOUND');
            $provenance = $existingContext->provenanceClass;
            $evidenceRefs = $existingContext->evidenceRefs;
            $scopeCode = $existingContext->scopeCode;
        } else {
            if (trim((string) ($input['provenance'] ?? '')) === '') return $this->blocked($base,'PROVENANCE_REQUIRED');
            if (($input['evidence_refs'] ?? []) === []) return $this->blocked($base,'EVIDENCE_REQUIRED');
            if (in_array($operation,['REPLACE'],true) && trim((string) ($input['edge_uuid'] ?? '')) === '') return $this->blocked($base,'EXACT_EDGE_REQUIRED');
            if (in_array($operation,['REPLACE'],true) && (int) ($input['expected_edge_revision'] ?? 0) < 1) return $this->blocked($base,'STALE_EDGE_REVISION');
            $provenance = $input['provenance'];
            $evidenceRefs = $input['evidence_refs'];
            $scopeCode = $input['scope_code'] ?? $context['scope_code'];
        }
        $plan = ['operation'=>$operation,'source'=>$source,'target'=>$target,'predicate'=>$predicate,'edge_uuid'=>$input['edge_uuid'] ?? null,'expected_edge_revision'=>$input['expected_edge_revision'] ?? null,'expected_context_revision'=>$existingContext?->revision,'source_revision'=>(int) ($sourceState['revision'] ?? 0),'target_revision'=>(int) ($targetState['revision'] ?? 0),'scope_code'=>$scopeCode,'provenance'=>$provenance,'evidence_refs'=>$evidenceRefs,'idempotency_key'=>(string) ($input['idempotency_key'] ?? ''),'registry_version'=>$this->registryVersion,'registry_hash'=>$this->registryHash];
        $base['source_revision'] = $plan['source_revision']; $base['target_revision'] = $plan['target_revision']; $base['plan'] = $plan; $base['plan_fingerprint'] = $this->fingerprint($plan); $base['status']='READY'; return $base;
    }
    public function read(array $input = []): array
    {
        if (!is_object($this->contexts) || !is_object($this->graph)) return ['status' => 'unavailable', 'reason' => 'GRAPH_RELATION_GOVERNANCE_UNAVAILABLE'];
        $context = null;
        if (trim((string) ($input['context_uuid'] ?? '')) !== '' && method_exists($this->contexts, 'findByContextUuid')) $context = $this->contexts->findByContextUuid((string) $input['context_uuid']);
        if ($context === null && trim((string) ($input['edge_uuid'] ?? '')) !== '' && method_exists($this->contexts, 'findByEdgeUuid')) $context = $this->contexts->findByEdgeUuid((string) $input['edge_uuid']);
        if ($context === null) return ['status' => 'not_found', 'reason' => 'GRAPH_RELATION_CONTEXT_NOT_FOUND'];
        $edge = $this->graph->findByUuid($context->edgeUuid);
        return ['status' => 'available', 'edge' => $edge === null ? null : ['edge_uuid' => $edge->edge_uuid, 'source' => ['type' => $edge->source->reference->endpoint_type, 'id' => $edge->source->reference->endpoint_key], 'predicate' => $edge->predicate, 'target' => ['type' => $edge->target->reference->endpoint_type, 'id' => $edge->target->reference->endpoint_key], 'state' => $edge->state->name, 'revision' => $edge->revision], 'context' => $context->toArray()];
    }
    public function apply(array $plan, string $approvedFingerprint, string $idempotencyKey): array
    {
        if (!hash_equals($this->fingerprint($plan), $approvedFingerprint)) return ['status'=>'REPLAN_REQUIRED','blockers'=>['APPROVAL_FINGERPRINT_MISMATCH']];
        if (($plan['registry_hash'] ?? '') !== $this->registryHash) return ['status'=>'REPLAN_REQUIRED','blockers'=>['REGISTRY_HASH_CHANGED']];
        $source = $plan['source']; $target = $plan['target']; $now = ($this->endpointState)($source['type'],$source['id']); $then = ($this->endpointState)($target['type'],$target['id']);
        if ((int) ($now['revision'] ?? 0) !== (int) ($plan['source_revision'] ?? 0)) return ['status'=>'REPLAN_REQUIRED','blockers'=>['STALE_SOURCE_REVISION']];
        if ((int) ($then['revision'] ?? 0) !== (int) ($plan['target_revision'] ?? 0)) return ['status'=>'REPLAN_REQUIRED','blockers'=>['STALE_TARGET_REVISION']];
        if ($idempotencyKey !== (string) ($plan['idempotency_key'] ?? '')) return ['status'=>'BLOCKED','blockers'=>['IDEMPOTENCY_KEY_MISMATCH']];
        if (!is_object($this->graph) || !is_object($this->contexts)) return ['status'=>'FAILED_FINAL','blockers'=>['GRAPH_APPLIER_NOT_CONFIGURED']];
        $existing = method_exists($this->contexts, 'findByIdempotencyKey') ? $this->contexts->findByIdempotencyKey($idempotencyKey) : null;
        if ($existing !== null) { $read = $this->canonicalRead($existing->edgeUuid); return ['status'=>'READ_BACK_VERIFIED','operation'=>$plan['operation'],'edge'=>$read['edge']->edge_uuid,'context'=>$read['context']->toArray(),'idempotent_replay'=>true]; }
        try {
            $apply = function () use ($plan, $approvedFingerprint, $idempotencyKey, $source, $target): array {
            $operation = (string) $plan['operation'];
            if ($operation === 'ADD') {
                $edge = $this->graph->create(new NodeReference($source['type'],$source['id']), (string) $plan['predicate'], new NodeReference($target['type'],$target['id']));
                $context = \NHK\Core\Application\Graph\GraphRelationContextPolicy::create(['edge_uuid'=>$edge->edge_uuid,'source_revision'=>$plan['source_revision'],'target_revision'=>$plan['target_revision'],'scope_code'=>$plan['scope_code'],'scope_subject_type'=>$source['type'],'scope_subject_id'=>$source['id'],'provenance_class'=>$plan['provenance'],'evidence_refs'=>$plan['evidence_refs'],'approval_fingerprint'=>$approvedFingerprint,'idempotency_key'=>$idempotencyKey]);
                $this->contexts->create($context);
                $read = $this->canonicalRead($edge->edge_uuid);
                return ['status'=>'READ_BACK_VERIFIED','operation'=>'ADD','edge'=>$read['edge']->edge_uuid,'context'=>$read['context']->toArray()];
            }
            $edgeUuid = (string) ($plan['edge_uuid'] ?? '');
            $edge = $this->graph->findByUuid($edgeUuid);
            if ($edge === null) return ['status'=>'FAILED_FINAL','blockers'=>['RELATION_NOT_FOUND']];
            $context = method_exists($this->contexts,'findByEdgeUuid') ? $this->contexts->findByEdgeUuid($edgeUuid) : null;
            if ($operation === 'RETIRE') { $updated = $this->graph->retire($edgeUuid,(int)$plan['expected_edge_revision']); if ($context !== null) $context = $this->contexts->retire($context,(int)($plan['expected_context_revision'] ?? $context->revision)); }
            elseif ($operation === 'REACTIVATE') { $updated = $this->graph->reactivate($edgeUuid,(int)$plan['expected_edge_revision']); if ($context !== null) $context = $this->contexts->reactivate($context,(int)($plan['expected_context_revision'] ?? $context->revision)); }
            else { if ($context === null) return ['status'=>'FAILED_FINAL','blockers'=>['RELATION_CONTEXT_NOT_FOUND']]; $context = $this->contexts->update($context,(int)($plan['expected_context_revision'] ?? $context->revision)); $updated = $edge; }
            $read = $this->canonicalRead($updated->edge_uuid);
            return ['status'=>'READ_BACK_VERIFIED','operation'=>$operation,'edge'=>$read['edge']->edge_uuid,'context'=>$read['context']->toArray()];
            };
            $result = $this->transactions !== null ? $this->transactions->transactional($apply) : $apply();
            if (($result['status'] ?? '') === 'READ_BACK_VERIFIED' && !($result['idempotent_replay'] ?? false) && is_callable($this->invalidate)) ($this->invalidate)($result);
            return $result;
        } catch (\Throwable $error) { return ['status'=>'FAILED_FINAL','blockers'=>[$error->getMessage()]]; }
    }
    private function locator(mixed $value):?array { if (!is_array($value)) return null; $type=trim((string)($value['type']??''));$id=trim((string)($value['id']??'')); return $type!==''&&$id!==''?['type'=>$type,'id'=>$id]:null; }
    private function canonicalRead(string $edgeUuid): array { $edge=$this->graph->findByUuid($edgeUuid); $context=$this->contexts->findByEdgeUuid($edgeUuid); if($edge===null||$context===null) throw new \RuntimeException('GRAPH_RELATION_READ_BACK_FAILED'); return ['edge'=>$edge,'context'=>$context]; }
    private function blocked(array $base,string $code):array{$base['status']='BLOCKED';$base['blockers'][]=$code;return $base;}
    private function fingerprint(array $plan):string{return hash('sha256',json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
}
