<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Authority;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity,AuthorityState};
use NHK\Core\Authority\Exception\{AuthorityRevisionConflict,StableKeyCollision};
use NHK\Core\Shared\Uuid\UuidCodec;
final class WpdbAuthorityRepository implements AuthorityRepository {
 private AuthorityRowHydrator $hydrator;
 /** @param callable(string, string|null): void|null $rowErrorSink */
 public function __construct(mixed $hydrator=null, private $rowErrorSink=null){$this->hydrator=$hydrator instanceof AuthorityRowHydrator?$hydrator:new AuthorityRowHydrator();}
 private function table():string{global $wpdb;return $wpdb->prefix.'nhk_entities';}
 private function row(?array $r):?AuthorityEntity{if(!$r)return null;try{return $this->hydrator->hydrate($r);}catch(MalformedAuthorityRow $error){if(is_callable($this->rowErrorSink))($this->rowErrorSink)($error->reasonCode,$error->stableKey,$r);return null;}}
 public function findByCanonicalId(string $id):?AuthorityEntity{global $wpdb;$r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table().' WHERE canonical_uuid=%s',UuidCodec::toBinary($id)),ARRAY_A);return $this->row($r);}
 public function findByStableKey(string $type,string $key):?AuthorityEntity{global $wpdb;return $this->row($wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table().' WHERE entity_type=%s AND stable_key=%s',$type,$key),ARRAY_A));}
 public function create(AuthorityEntity $e):AuthorityEntity{
  global $wpdb;
  $existingById=$this->findByCanonicalId($e->canonicalId);
  if($existingById!==null){if($this->sameEntity($existingById,$e))return $existingById;throw new StableKeyCollision('Stable key already exists.');}
  $existingByKey=$this->findByStableKey($e->entityType,$e->stableKey);
  if($existingByKey!==null){if($this->sameEntity($existingByKey,$e))return $existingByKey;throw new StableKeyCollision('Stable key already exists.');}
  $now=gmdate('Y-m-d H:i:s.u');$payload=wp_json_encode($e->payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$ok=$wpdb->query($wpdb->prepare('INSERT INTO '.$this->table().' (canonical_uuid,entity_type,stable_key,canonical_name,schema_version,payload,state,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%d,%s,%d,%d,%s,%s)',UuidCodec::toBinary($e->canonicalId),$e->entityType,$e->stableKey,$e->canonicalName,$e->schemaVersion,$payload,$e->state->value,1,$now,$now));
  if($ok===false){$existing=$this->findByStableKey($e->entityType,$e->stableKey);if($existing&&$this->sameEntity($existing,$e))return $existing;if($existing)throw new StableKeyCollision('Stable key already exists.');throw new \RuntimeException('Authority insert failed: '.(string)$wpdb->last_error);}return $this->findByCanonicalId($e->canonicalId)??$e;}

 private function sameEntity(AuthorityEntity $left,AuthorityEntity $right):bool{return $left->entityType===$right->entityType&&$left->stableKey===$right->stableKey&&$left->canonicalName===$right->canonicalName&&$left->schemaVersion===$right->schemaVersion&&$left->payload===$right->payload&&$left->state===$right->state&&$left->revision===$right->revision&&$left->retiredAt===$right->retiredAt;}
 public function update(AuthorityEntity $e,int $expectedRevision):AuthorityEntity{global $wpdb;$now=gmdate('Y-m-d H:i:s.u');$ok=$wpdb->query($wpdb->prepare('UPDATE '.$this->table().' SET canonical_name=%s,payload=%s,state=%d,revision=revision+1,updated_at=%s,retired_at=%s WHERE canonical_uuid=%s AND revision=%d',$e->canonicalName,wp_json_encode($e->payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$e->state->value,$now,$e->retiredAt,UuidCodec::toBinary($e->canonicalId),$expectedRevision));if($ok!==1)throw new AuthorityRevisionConflict('Authority revision conflict.');return $this->findByCanonicalId($e->canonicalId)??$e;}
 public function listByType(string $type,bool $includeRetired=false):array{global $wpdb;$state=$includeRetired?'':' AND state=1';$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$this->table().' WHERE entity_type=%s'.$state.' ORDER BY id',$type),ARRAY_A)?:[];$items=[];foreach($rows as $row){try{$items[]=$this->hydrator->hydrate($row);}catch(MalformedAuthorityRow $error){if(is_callable($this->rowErrorSink))($this->rowErrorSink)($error->reasonCode,$error->stableKey,$row);}}return $items;}
}
