<?php
declare(strict_types=1);
namespace NHK\Core\Application\Authority;
use NHK\Core\Contracts\Authority\{AuthorityRepository,AuthorityAuditSink};
use NHK\Core\Domain\Authority\{AuthorityEntity,AuthorityState,EntityTypeRegistry};
use NHK\Core\Authority\Exception\{AuthorityAlreadyActive,AuthorityAlreadyRetired,AuthorityRevisionConflict,InvalidPayload,StableKeyCollision};
use NHK\Core\Shared\Uuid\UuidCodec;
final class AuthorityService {
 public function __construct(private AuthorityRepository $repo,private EntityTypeRegistry $types,private ?AuthorityAuditSink $audit=null){}
 public function create(string $type,string $key,string $name,array $payload=[]):AuthorityEntity { $d=$this->types->get($type);$this->validate($d->allowedFields,$payload);$existing=$this->repo->findByStableKey($type,$key);if($existing){if($existing->canonicalName===$name&&$existing->payload===$payload)return $existing;throw new StableKeyCollision('Stable key already exists.');} $e=new AuthorityEntity(UuidCodec::newV7(),$type,$key,$name,$d->schemaVersion,$payload);$e=$this->repo->create($e);$this->audit?->record('created',$e);return $e; }
 public function rename(string $id,string $name,int $revision):AuthorityEntity{return $this->mutate($id,$revision,fn(AuthorityEntity $e)=>new AuthorityEntity($e->canonicalId,$e->entityType,$e->stableKey,$name,$e->schemaVersion,$e->payload,$e->state,$e->revision,$e->createdAt,$e->updatedAt,$e->retiredAt),'renamed');}
 public function retire(string $id,int $revision):AuthorityEntity{return $this->mutate($id,$revision,fn(AuthorityEntity $e)=>new AuthorityEntity($e->canonicalId,$e->entityType,$e->stableKey,$e->canonicalName,$e->schemaVersion,$e->payload,AuthorityState::RETIRED,$e->revision,$e->createdAt,$e->updatedAt,gmdate('Y-m-d H:i:s.u')),'retired');}
 public function reactivate(string $id,int $revision):AuthorityEntity{return $this->mutate($id,$revision,fn(AuthorityEntity $e)=>new AuthorityEntity($e->canonicalId,$e->entityType,$e->stableKey,$e->canonicalName,$e->schemaVersion,$e->payload,AuthorityState::ACTIVE,$e->revision,$e->createdAt,$e->updatedAt,null),'reactivated');}
 public function list(string $type,bool $includeRetired=false):array{return $this->repo->listByType($type,$includeRetired);}
 private function mutate(string $id,int $revision,callable $fn,string $event):AuthorityEntity{$e=$this->repo->findByCanonicalId($id);if(!$e)throw new AuthorityRevisionConflict('Entity not found.');if($event==='retired'&&!$e->active())throw new AuthorityAlreadyRetired('Authority is already retired.');if($event==='reactivated'&&$e->active())throw new AuthorityAlreadyActive('Authority is already active.');$n=$fn($e);if($n->canonicalName===$e->canonicalName&&$n->state===$e->state&&$n->retiredAt===$e->retiredAt)return $e;$n=$this->repo->update($n,$revision);$this->audit?->record($event,$n);return $n;}
 private function validate(array $allowed,array $payload):void{foreach(array_keys($payload) as $key)if(!in_array($key,$allowed,true))throw new InvalidPayload('Unknown payload field: '.$key);}
}
