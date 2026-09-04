<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\PublicIdentity;

use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Infrastructure\Migration\PublicIdentityMigration014;
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbPublicIdentityRepository implements PublicIdentityRepository
{
    public function __construct(private object $wpdb) {}
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
    public function resolveHistoric(string $path): array { if (!PublicIdentityMigration014::schemaReady($this->wpdb)) return ['status'=>'UNAVAILABLE']; $rows=$this->wpdb->get_results($this->wpdb->prepare('SELECT h.*,i.current_slug,i.route_type,i.revision FROM '.$this->historyTable().' h LEFT JOIN '.$this->currentTable().' i ON i.identity_uuid=h.identity_uuid WHERE h.route_path=%s',$path),ARRAY_A)?:[]; if(count($rows)!==1)return ['status'=>count($rows)>1?'AMBIGUOUS':'NOT_FOUND']; $r=$rows[0]; if(!isset($r['current_slug']))return ['status'=>'INELIGIBLE']; return ['status'=>'FOUND','target'=>$this->path((string)$r['route_type'],(string)$r['current_slug']),'hops'=>1]; }
    private function path(string $type,string $slug):string { $prefix=match($type){'video'=>'/video/','movement'=>'/bo-may/','music'=>'/ban-nhac/','component'=>'/linh-kien/','classification'=>'/phan-loai/','specimen'=>'/hien-vat/','product'=>'/san-pham/',default=>'/'}; return $prefix.$slug.'/'; }
    private function hydrate(array $r):array { return ['identity_id'=>UuidCodec::fromBinary((string)$r['identity_uuid']),'owner_kind'=>(string)$r['owner_kind'],'owner_id'=>UuidCodec::fromBinary((string)$r['owner_uuid']),'route_type'=>(string)$r['route_type'],'current_slug'=>(string)$r['current_slug'],'collision_scope'=>(string)$r['collision_scope'],'route_policy_version'=>(string)$r['route_policy_version'],'revision'=>(int)$r['revision'],'current_path'=>$this->path((string)$r['route_type'],(string)$r['current_slug'])]; }
}
