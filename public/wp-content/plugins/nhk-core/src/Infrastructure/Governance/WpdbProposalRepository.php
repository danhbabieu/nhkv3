<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbProposalRepository implements ProposalRepository
{
    public function __construct(private ?object $database = null) {}
    private function db(): object { global $wpdb; return $this->database ?? $wpdb; }
    private function table(): string { return $this->db()->prefix . 'nhk_proposals'; }
    private function state(ProposalState $state): int { return array_search($state, ProposalState::cases(), true) + 1; }
    private function hydrate(?array $row): ?Proposal {
        if (!$row) return null;
        $state = ProposalState::cases()[max(0, (int) $row['state'] - 1)] ?? ProposalState::DRAFT;
        $target = !empty($row['target_uuid']) ? UuidCodec::fromBinary($row['target_uuid']) : null;
        return new Proposal(UuidCodec::fromBinary($row['proposal_uuid']), (string) $row['entity_type'], (string) $row['operation'], json_decode((string) $row['command_json'], true, 512, JSON_THROW_ON_ERROR), bin2hex((string) $row['fingerprint']), $row['expected_revision'] === null ? 1 : (int) $row['expected_revision'], '', $state, (string) $row['created_by'], null, null, (string) $row['idempotency_key'], (int) $row['revision'], $row['submitted_at'], $row['applied_at'], $target, (string) $row['entity_type'], $row['created_at'], $row['updated_at']);
    }
    public function create(Proposal $proposal): Proposal {
        $db = $this->db(); $now = gmdate('Y-m-d H:i:s.u');
        $ok = $db->query($db->prepare('INSERT INTO '.$this->table().' (proposal_uuid,idempotency_key,operation,entity_type,target_uuid,expected_revision,command_json,fingerprint,state,revision,created_by,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,%s,%s)', UuidCodec::toBinary($proposal->id), $proposal->idempotencyKey, $proposal->operation, $proposal->entityType ?: $proposal->subjectId, $proposal->targetUuid ? UuidCodec::toBinary($proposal->targetUuid) : null, $proposal->expectedRevision, wp_json_encode($proposal->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), hex2bin($proposal->contentFingerprint), $this->state($proposal->state), $proposal->revision, (int) ($proposal->actor ?? 0), $now, $now));
        if ($ok === false) { $existing = $this->findByIdempotencyKey($proposal->idempotencyKey); if ($existing) return $existing; throw new \RuntimeException('PROPOSAL_INSERT_FAILED: '.(string) $db->last_error); }
        return $this->find($proposal->id) ?? $proposal;
    }
    public function find(string $id): ?Proposal { $db=$this->db(); return $this->hydrate($db->get_row($db->prepare('SELECT * FROM '.$this->table().' WHERE proposal_uuid=%s LIMIT 1',UuidCodec::toBinary($id)),ARRAY_A)); }
    public function findByIdempotencyKey(string $key): ?Proposal { $db=$this->db(); return $this->hydrate($db->get_row($db->prepare('SELECT * FROM '.$this->table().' WHERE idempotency_key=%s LIMIT 1',$key),ARRAY_A)); }
    public function save(Proposal $proposal): Proposal { $db=$this->db(); $ok=$db->query($db->prepare('UPDATE '.$this->table().' SET state=%d,revision=%d,updated_at=%s,submitted_at=%s,applied_at=%s WHERE proposal_uuid=%s AND revision=%d',$this->state($proposal->state),$proposal->revision,gmdate('Y-m-d H:i:s.u'),$proposal->submittedAt,$proposal->appliedAt,UuidCodec::toBinary($proposal->id),$proposal->revision-1)); if($ok!==1)throw new \RuntimeException('PROPOSAL_REVISION_CONFLICT'); return $this->find($proposal->id)??$proposal; }
    public function findForUpdate(string $id): ?Proposal { $db=$this->db(); return $this->hydrate($db->get_row($db->prepare('SELECT * FROM '.$this->table().' WHERE proposal_uuid=%s LIMIT 1 FOR UPDATE',UuidCodec::toBinary($id)),ARRAY_A)); }
}
