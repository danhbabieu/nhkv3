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
    private function normalizedFingerprint(string $value): string { return preg_match('/^[a-f0-9]{64}$/i', $value) ? strtolower($value) : hash('sha256', $value); }
    private function fingerprintBinary(string $value): string { return hex2bin($this->normalizedFingerprint($value)); }
    private function sameIdempotentContent(Proposal $existing, Proposal $proposal): bool
    {
        return ($existing->entityType ?: $existing->subjectId) === ($proposal->entityType ?: $proposal->subjectId)
            && $existing->operation === $proposal->operation
            && $existing->payload === $proposal->payload
            && $existing->targetUuid === $proposal->targetUuid
            && $this->normalizedFingerprint($existing->contentFingerprint) === $this->normalizedFingerprint($proposal->contentFingerprint)
            && $existing->expectedRevision === $proposal->expectedRevision
            && $this->normalizedFingerprint($existing->dependencyFingerprint) === $this->normalizedFingerprint($proposal->dependencyFingerprint);
    }
    private function hydrate(?array $row): ?Proposal {
        if (!$row) return null;
        try {
            $stateValue = (int) $row['state'];
            $states = ProposalState::cases();
            if ($stateValue < 1 || $stateValue > count($states)) return null;
            $state = $states[$stateValue - 1];
            $targetBinary = (string) ($row['target_uuid'] ?? '');
            $target = $targetBinary !== '' && trim($targetBinary, "\0") !== '' ? UuidCodec::fromBinary($targetBinary) : null;
            $decisionActor = null;
            $supersededBy = null;
            $proposalDbId = (int) ($row['id'] ?? 0);
            if ($proposalDbId > 0) {
                $approval = $this->db()->get_var($this->db()->prepare('SELECT approved_by FROM '.$this->db()->prefix.'nhk_proposal_approvals WHERE proposal_id=%d ORDER BY id DESC LIMIT 1', $proposalDbId));
                $decisionActor = $approval !== null ? (string) $approval : null;
                if (!empty($row['superseded_by_proposal_id'])) {
                    $replacementUuid = $this->db()->get_var($this->db()->prepare('SELECT proposal_uuid FROM '.$this->table().' WHERE id=%d', (int) $row['superseded_by_proposal_id']));
                    $supersededBy = $replacementUuid ? UuidCodec::fromBinary((string) $replacementUuid) : null;
                }
            }
            $payload = json_decode((string) ($row['command_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) return null;
            return new Proposal(UuidCodec::fromBinary($row['proposal_uuid']), (string) $row['entity_type'], (string) $row['operation'], $payload, bin2hex((string) $row['fingerprint']), $row['expected_revision'] === null ? null : (int) $row['expected_revision'], !empty($row['dependency_fingerprint']) ? bin2hex((string) $row['dependency_fingerprint']) : 'legacy', $state, (string) $row['created_by'], $decisionActor, null, (string) $row['idempotency_key'], (int) $row['revision'], $row['submitted_at'], $row['applied_at'], $target, (string) $row['entity_type'], $row['created_at'], $row['updated_at'], $row['cancelled_at'], $row['rejected_at'], $row['superseded_at'], $supersededBy);
        } catch (\InvalidArgumentException|\JsonException) {
            return null;
        }
    }
    public function create(Proposal $proposal): Proposal {
        $db = $this->db();
        $existing = $this->findByIdempotencyKey($proposal->idempotencyKey);
        if ($existing !== null) {
            if ($this->sameIdempotentContent($existing, $proposal)) return $existing;
            throw new \NHK\Core\Governance\Exception\ProposalIdempotencyConflict('Idempotency key is already bound to different content.');
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $db->query($db->prepare('INSERT INTO '.$this->table().' (proposal_uuid,idempotency_key,operation,entity_type,target_uuid,expected_revision,command_json,fingerprint,dependency_fingerprint,state,revision,created_by,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,%s,%s)', UuidCodec::toBinary($proposal->id), $proposal->idempotencyKey, $proposal->operation, $proposal->entityType ?: $proposal->subjectId, $proposal->targetUuid ? UuidCodec::toBinary($proposal->targetUuid) : null, $proposal->expectedRevision, wp_json_encode($proposal->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->fingerprintBinary($proposal->contentFingerprint), $this->fingerprintBinary($proposal->dependencyFingerprint), $this->state($proposal->state), $proposal->revision, (int) ($proposal->actor ?? 0), $now, $now));
        if ($ok === false) {
            // The unique idempotency index is the race-safe serialization point.
            $existing = $this->findByIdempotencyKey($proposal->idempotencyKey);
            if ($existing) {
                if ($this->sameIdempotentContent($existing, $proposal)) return $existing;
                throw new \NHK\Core\Governance\Exception\ProposalIdempotencyConflict('Idempotency key is already bound to different content.');
            }
            throw new \RuntimeException('PROPOSAL_INSERT_FAILED: '.(string) $db->last_error);
        }
        return $this->find($proposal->id) ?? $proposal;
    }
    public function find(string $id): ?Proposal { $db=$this->db(); return $this->hydrate($db->get_row($db->prepare('SELECT * FROM '.$this->table().' WHERE proposal_uuid=%s LIMIT 1',UuidCodec::toBinary($id)),ARRAY_A)); }
    public function findByIdempotencyKey(string $key): ?Proposal { $db=$this->db(); return $this->hydrate($db->get_row($db->prepare('SELECT * FROM '.$this->table().' WHERE idempotency_key=%s LIMIT 1',$key),ARRAY_A)); }
    public function save(Proposal $proposal): Proposal { $db=$this->db(); $replacementDbId=$proposal->supersededByProposalId ? $db->get_var($db->prepare('SELECT id FROM '.$this->table().' WHERE proposal_uuid=%s',UuidCodec::toBinary($proposal->supersededByProposalId))) : null; $ok=$db->query($db->prepare('UPDATE '.$this->table().' SET state=%d,revision=%d,updated_at=%s,submitted_at=%s,applied_at=%s,cancelled_at=%s,rejected_at=%s,superseded_at=%s,superseded_by_proposal_id=%s WHERE proposal_uuid=%s AND revision=%d',$this->state($proposal->state),$proposal->revision,gmdate('Y-m-d H:i:s.u'),$proposal->submittedAt,$proposal->appliedAt,$proposal->cancelledAt,$proposal->rejectedAt,$proposal->supersededAt,$replacementDbId,UuidCodec::toBinary($proposal->id),$proposal->revision-1)); if($ok!==1)throw new \RuntimeException('PROPOSAL_REVISION_CONFLICT'); return $this->find($proposal->id)??$proposal; }
    public function findForUpdate(string $id): ?Proposal { $db=$this->db(); return $this->hydrate($db->get_row($db->prepare('SELECT * FROM '.$this->table().' WHERE proposal_uuid=%s LIMIT 1 FOR UPDATE',UuidCodec::toBinary($id)),ARRAY_A)); }

    public function recordApproval(Proposal $proposal, string $actor): void
    {
        $db = $this->db();
        $proposalId = $db->get_var($db->prepare('SELECT id FROM '.$this->table().' WHERE proposal_uuid=%s', UuidCodec::toBinary($proposal->id)));
        if (!$proposalId) throw new \RuntimeException('PROPOSAL_NOT_FOUND');
        $ok = $db->query($db->prepare('INSERT INTO '.$db->prefix.'nhk_proposal_approvals (approval_uuid,proposal_id,proposal_revision,fingerprint,approved_by,approved_at) VALUES (%s,%d,%d,%s,%d,%s)', UuidCodec::toBinary(UuidCodec::newV7()), (int) $proposalId, $proposal->revision, $this->fingerprintBinary($proposal->bindingFingerprint()), (int) $actor, gmdate('Y-m-d H:i:s.u')));
        if ($ok === false) throw new \RuntimeException('APPROVAL_INSERT_FAILED: '.(string) $db->last_error);
    }

    public function latestApproval(string $proposalId): ?array
    {
        $db = $this->db();
        $proposalDbId = $db->get_var($db->prepare('SELECT id FROM '.$this->table().' WHERE proposal_uuid=%s', UuidCodec::toBinary($proposalId)));
        if (!$proposalDbId) return null;
        return $db->get_row($db->prepare('SELECT * FROM '.$db->prefix.'nhk_proposal_approvals WHERE proposal_id=%d ORDER BY id DESC LIMIT 1', (int) $proposalDbId), ARRAY_A) ?: null;
    }
}
