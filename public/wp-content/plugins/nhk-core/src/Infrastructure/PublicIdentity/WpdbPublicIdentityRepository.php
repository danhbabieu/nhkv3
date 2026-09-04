<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\PublicIdentity;

use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Domain\PublicIdentity\{HistoricPublicRoute, PublicIdentity, PublicIdentityMutationResult};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbPublicIdentityRepository implements PublicIdentityRepository
{
    public function __construct(private object $wpdb) {}
    public function findByOwner(string $ownerKind, string $ownerId): ?PublicIdentity
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM '.$this->currentTable().' WHERE owner_kind=%s AND owner_uuid=%s LIMIT 1', $ownerKind, UuidCodec::toBinary($ownerId)), ARRAY_A);
        return is_array($row) ? $this->toDomain($row) : null;
    }
    public function findByRoute(string $routeType, string $collisionScope, string $slug): ?PublicIdentity
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM '.$this->currentTable().' WHERE route_type=%s AND collision_scope=%s AND current_slug=%s LIMIT 1', $routeType, $collisionScope, $slug), ARRAY_A);
        return is_array($row) ? $this->toDomain($row) : null;
    }
    public function create(PublicIdentity $identity): PublicIdentityMutationResult
    {
        $result = $this->allocate(['owner_kind'=>$identity->ownerKind,'owner_id'=>$identity->ownerId,'route_type'=>$identity->routeType,'collision_scope'=>$identity->collisionScope,'current_slug'=>$identity->currentSlug,'current_path'=>'/'.$identity->currentSlug.'/','route_policy_version'=>$identity->routePolicyVersion], 'domain:'.$identity->identityId);
        return PublicIdentityMutationResult::accepted(new PublicIdentity($identity->identityId,$identity->ownerKind,$identity->ownerId,$identity->routeType,$identity->currentSlug,$identity->collisionScope,$identity->routePolicyVersion,(int)($result['revision'] ?? 1),$identity->createdAt,$identity->updatedAt));
    }
    public function update(PublicIdentity $identity, int $expectedRevision): PublicIdentityMutationResult
    {
        $current = $this->findCurrentById($identity->identityId); if ($current === null) return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::UNAVAILABLE_STORAGE);
        $result = $this->change(['identity_id'=>$identity->identityId,'owner_kind'=>$identity->ownerKind,'owner_id'=>$identity->ownerId,'route_type'=>$identity->routeType,'collision_scope'=>$identity->collisionScope,'current_slug'=>$identity->currentSlug,'current_path'=>'/'.$identity->currentSlug.'/','route_policy_version'=>$identity->routePolicyVersion], $current['current_path'], $expectedRevision, 'domain:'.$identity->identityId.':'.$identity->revision);
        return PublicIdentityMutationResult::accepted(new PublicIdentity($identity->identityId,$identity->ownerKind,$identity->ownerId,$identity->routeType,$identity->currentSlug,$identity->collisionScope,$identity->routePolicyVersion,(int)($result['revision'] ?? $identity->revision),$identity->createdAt,$identity->updatedAt));
    }
    public function appendHistoricRoute(HistoricPublicRoute $historicRoute): PublicIdentityMutationResult
    {
        if ($this->findByRoute($historicRoute->routeType, $historicRoute->collisionScope, $historicRoute->oldSlug) !== null) return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        $row = $this->wpdb->get_var($this->wpdb->prepare('SELECT id FROM '.$this->historyTable().' WHERE route_type=%s AND collision_scope=%s AND route_path=%s LIMIT 1', $historicRoute->routeType, $historicRoute->collisionScope, $historicRoute->path));
        if ($row !== null) return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        $ok = $this->wpdb->query($this->wpdb->prepare('INSERT INTO '.$this->historyTable().' (identity_uuid,route_type,collision_scope,route_path,old_slug,revision,created_at) VALUES (%s,%s,%s,%s,%s,%d,%s)', UuidCodec::toBinary($historicRoute->identityId),$historicRoute->routeType,$historicRoute->collisionScope,$historicRoute->path,$historicRoute->oldSlug,$historicRoute->replacementRevision,$historicRoute->createdAt ?? gmdate('Y-m-d H:i:s.u')));
        return $ok === 1 ? PublicIdentityMutationResult::acceptedHistoricRoute($historicRoute) : PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::UNAVAILABLE_STORAGE);
    }
    private function currentTable(): string { return $this->wpdb->prefix . 'nhk_public_identities'; }
    private function historyTable(): string { return $this->wpdb->prefix . 'nhk_public_identity_history'; }
    public function allocate(array $r, string $key): array
    {
        $prior = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM '.$this->currentTable().' WHERE idempotency_key=%s', $key), ARRAY_A);
        if (is_array($prior)) return $this->hydrate($prior);
        $historic = $this->wpdb->get_var($this->wpdb->prepare('SELECT id FROM '.$this->historyTable().' WHERE route_type=%s AND route_path=%s', $r['route_type'], $r['current_path']));
        if ($historic !== null) throw new \RuntimeException('PUBLIC_IDENTITY_ROUTE_COLLISION');
        $now = gmdate('Y-m-d H:i:s.u'); $id = UuidCodec::newV7();
        $ok = $this->wpdb->query($this->wpdb->prepare('INSERT INTO '.$this->currentTable().' (identity_uuid,owner_kind,owner_uuid,route_type,current_slug,collision_scope,route_policy_version,revision,idempotency_key,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,1,%s,%s,%s)', UuidCodec::toBinary($id),$r['owner_kind'],UuidCodec::toBinary($r['owner_id']),$r['route_type'],$r['current_slug'],$r['collision_scope'],$r['route_policy_version'],$key,$now,$now));
        if ($ok !== 1) throw new \RuntimeException('PUBLIC_IDENTITY_STORAGE_UNAVAILABLE');
        $r['identity_id']=$id; $r['revision']=1; return $r;
    }
    public function change(array $r, string $oldPath, int $expectedRevision, string $key): array
    {
        $prior = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM '.$this->currentTable().' WHERE idempotency_key=%s', $key), ARRAY_A);
        if (is_array($prior)) return $this->hydrate($prior);
        $current = $this->findCurrentById((string) ($r['identity_id'] ?? ''));
        if ($current === null) throw new \RuntimeException('NOT_FOUND');
        $historic = $this->wpdb->get_var($this->wpdb->prepare('SELECT id FROM '.$this->historyTable().' WHERE route_type=%s AND route_path=%s', $r['route_type'], $r['current_path']));
        if ($historic !== null) throw new \RuntimeException('PUBLIC_IDENTITY_ROUTE_COLLISION');
        $currentCollision = $this->wpdb->get_var($this->wpdb->prepare('SELECT id FROM '.$this->currentTable().' WHERE route_type=%s AND collision_scope=%s AND current_slug=%s AND identity_uuid<>%s', $r['route_type'], $r['collision_scope'], $r['current_slug'], UuidCodec::toBinary((string)$r['identity_id'])));
        if ($currentCollision !== null) throw new \RuntimeException('PUBLIC_IDENTITY_ROUTE_COLLISION');
        $now=gmdate('Y-m-d H:i:s.u');
        $this->wpdb->query('START TRANSACTION');
        $ok=$this->wpdb->query($this->wpdb->prepare('UPDATE '.$this->currentTable().' SET current_slug=%s,revision=revision+1,idempotency_key=%s,updated_at=%s WHERE identity_uuid=%s AND revision=%d',$r['current_slug'],$key,$now,UuidCodec::toBinary((string)$r['identity_id']),$expectedRevision));
        if ($ok !== 1) { $this->wpdb->query('ROLLBACK'); throw new \RuntimeException('STALE_REVISION'); }
        $historyOk=$this->wpdb->query($this->wpdb->prepare('INSERT INTO '.$this->historyTable().' (identity_uuid,route_type,route_path,old_slug,revision,created_at) VALUES (%s,%s,%s,%s,%d,%s)',UuidCodec::toBinary((string)$r['identity_id']),$current['route_type'],$oldPath,$current['current_slug'],$expectedRevision,$now));
        if ($historyOk !== 1) { $this->wpdb->query('ROLLBACK'); throw new \RuntimeException('PUBLIC_IDENTITY_STORAGE_UNAVAILABLE'); }
        $this->wpdb->query('COMMIT');
        $r['revision']=$expectedRevision+1; return $r;
    }
    public function findCurrentById(string $id): ?array { $row=$this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM '.$this->currentTable().' WHERE identity_uuid=%s',UuidCodec::toBinary($id)),ARRAY_A); return is_array($row)?$this->hydrate($row):null; }
    public function resolveHistoric(string $path): array { return ['status'=>'NOT_FOUND']; }
    public function resolveExact(string $routeType, string $scope, string $path): PublicIdentityMutationResult { $rows=$this->wpdb->get_results($this->wpdb->prepare('SELECT h.*,i.* FROM '.$this->historyTable().' h LEFT JOIN '.$this->currentTable().' i ON i.identity_uuid=h.identity_uuid WHERE h.route_type=%s AND h.collision_scope=%s AND h.route_path=%s',$routeType,$scope,$path),ARRAY_A)?:[]; if(count($rows)!==1)return PublicIdentityMutationResult::rejected(count($rows)>1?PublicIdentityMutationResult::AMBIGUOUS_HISTORY:PublicIdentityMutationResult::UNKNOWN_ROUTE); $r=$rows[0]; if(!isset($r['current_slug']))return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT); $identity=$this->toDomain($r); $history=new HistoricPublicRoute(UuidCodec::fromBinary((string)$r['identity_uuid']),$routeType,$scope,(string)$r['route_path'],(string)$r['old_slug'],(int)$r['revision'],$r['created_at']??null); return PublicIdentityMutationResult::acceptedHistoricRoute($history,$identity); }
    private function path(string $type,string $slug):string { $prefix=match($type){'video'=>'/video/','movement'=>'/bo-may/','music'=>'/ban-nhac/','component'=>'/linh-kien/','classification'=>'/phan-loai/','specimen'=>'/hien-vat/','product'=>'/san-pham/',default=>'/'}; return $prefix.$slug.'/'; }
    private function hydrate(array $r):array { return ['identity_id'=>UuidCodec::fromBinary((string)$r['identity_uuid']),'owner_kind'=>(string)$r['owner_kind'],'owner_id'=>UuidCodec::fromBinary((string)$r['owner_uuid']),'route_type'=>(string)$r['route_type'],'current_slug'=>(string)$r['current_slug'],'collision_scope'=>(string)$r['collision_scope'],'route_policy_version'=>(string)$r['route_policy_version'],'revision'=>(int)$r['revision'],'current_path'=>$this->path((string)$r['route_type'],(string)$r['current_slug'])]; }
    private function toDomain(array $r): PublicIdentity { return new PublicIdentity((string)UuidCodec::fromBinary((string)$r['identity_uuid']),(string)$r['owner_kind'],(string)UuidCodec::fromBinary((string)$r['owner_uuid']),(string)$r['route_type'],(string)$r['current_slug'],(string)$r['collision_scope'],(string)$r['route_policy_version'],(int)$r['revision'],$r['created_at'] ?? null,$r['updated_at'] ?? null); }
}
