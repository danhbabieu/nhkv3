<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbProposalRepository implements ProposalRepository
{
    private function table(): string { global $wpdb; return $wpdb->prefix . 'nhk_proposals'; }

    private function state(ProposalState $state): int { return array_search($state, ProposalState::cases(), true) + 1; }

    private function row(?array $row): ?Proposal
    {
        if (!$row) return null;
        $state = ProposalState::cases()[max(0, (int)$row['state'] - 1)] ?? ProposalState::DRAFT;
        return new Proposal(
            UuidCodec::fromBinary($row['proposal_uuid']), '', $row['operation'], json_decode($row['command_json'], true, 512, JSON_THROW_ON_ERROR),
            bin2hex($row['fingerprint']), $row['expected_revision'] === null ? 1 : (int)$row['expected_revision'], '', $state,
            (string)$row['created_by'], null, null, $row['idempotency_key'], (int)$row['revision'], $row['submitted_at'], $row['applied_at']
        );
    }

    public function create(Proposal $proposal): Proposal
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $wpdb->query($wpdb->prepare('INSERT INTO ' . $this->table() . ' (proposal_uuid,idempotency_key,operation,entity_type,target_uuid,expected_revision,command_json,fingerprint,state,revision,created_by,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,%s,%s)', UuidCodec::toBinary($proposal->id), $proposal->idempotencyKey, $proposal->operation, $proposal->subjectId, null, $proposal->expectedRevision, wp_json_encode($proposal->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), hex2bin($proposal->contentFingerprint), $this->state($proposal->state), $proposal->revision, (int)($proposal->actor ?? 0), $now, $now));
        if ($ok === false) return $this->findByIdempotencyKey($proposal->idempotencyKey) ?? throw new \RuntimeException('Proposal insert failed: ' . (string)$wpdb->last_error);
        return $this->find($proposal->id) ?? $proposal;
    }

    public function find(string $id): ?Proposal { global $wpdb; return $this->row($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE proposal_uuid=%s', UuidCodec::toBinary($id)), ARRAY_A)); }
    public function findByIdempotencyKey(string $key): ?Proposal { global $wpdb; return $this->row($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE idempotency_key=%s', $key), ARRAY_A)); }

    public function save(Proposal $proposal): Proposal
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $wpdb->query($wpdb->prepare('UPDATE ' . $this->table() . ' SET state=%d,revision=%d,updated_at=%s,submitted_at=%s,applied_at=%s WHERE proposal_uuid=%s AND revision=%d', $this->state($proposal->state), $proposal->revision, $now, $proposal->submittedAt, $proposal->appliedAt, UuidCodec::toBinary($proposal->id), $proposal->revision - 1));
        if ($ok !== 1) throw new \RuntimeException('Proposal revision conflict.');
        return $this->find($proposal->id) ?? $proposal;
    }
}
