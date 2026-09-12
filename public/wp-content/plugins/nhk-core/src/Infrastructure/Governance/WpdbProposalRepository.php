<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Contracts\Governance\{ApprovedRelationProposalRepository,ProposalRepository};
use NHK\Core\Domain\Governance\{Proposal, ProposalState, ProposalSubjectBindingValidator};
use NHK\Core\Governance\Exception\ProposalIdempotencyStaleBinding;
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbProposalRepository implements ProposalRepository, ApprovedRelationProposalRepository
{
    public function __construct(private ?object $database = null) {}
    private function db(): object { global $wpdb; return $this->database ?? $wpdb; }
    private function table(): string { return $this->db()->prefix . 'nhk_proposals'; }
    private function nullableDate(mixed $value): ?string
    {
        $date = is_string($value) ? trim($value) : '';
        return $date === '' || str_starts_with($date, '0000-00-00') ? null : $date;
    }
    private function state(ProposalState $state): int { return array_search($state, ProposalState::cases(), true) + 1; }
    private function normalizedFingerprint(string $value): string { return preg_match('/^[a-f0-9]{64}$/i', $value) ? strtolower($value) : hash('sha256', $value); }
    private function fingerprintBinary(string $value): string { return hex2bin($this->normalizedFingerprint($value)); }
    private function sameIdempotentContent(Proposal $existing, Proposal $proposal): bool
    {
        return $existing->subjectId === $proposal->subjectId
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
            $hasTargetUuid = $targetBinary !== '' && strlen($targetBinary) === 16 && strtolower(bin2hex($targetBinary)) !== str_repeat('0', 32);
            $target = $hasTargetUuid ? UuidCodec::fromBinary($targetBinary) : null;
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
            $isRelationCreate = (string) ($row['operation'] ?? '') === 'relation_create';
            $isCreateWithoutTarget = in_array((string) ($row['operation'] ?? ''), ['create', 'ingest'], true) && !$hasTargetUuid;
            $expectedRevision = ($isRelationCreate || ($isCreateWithoutTarget && ($row['expected_revision'] === null || $row['expected_revision'] === '' || (string) $row['expected_revision'] === '0')))
                ? null
                : ($row['expected_revision'] === null || $row['expected_revision'] === '' ? null : (int) $row['expected_revision']);
            $subjectId = trim((string) ($row['subject_id'] ?? ''));
            if ($subjectId === '') $subjectId = (string) $row['entity_type'];
            if ((string) ($row['operation'] ?? '') === 'relation_create') {
                $subjectId = trim((string) ($payload['source_uuid'] ?? $payload['source_key'] ?? $subjectId));
            }
            return new Proposal(UuidCodec::fromBinary($row['proposal_uuid']), $subjectId, (string) $row['operation'], $payload, bin2hex((string) $row['fingerprint']), $expectedRevision, !empty($row['dependency_fingerprint']) ? bin2hex((string) $row['dependency_fingerprint']) : 'legacy', $state, (string) $row['created_by'], $decisionActor, null, (string) $row['idempotency_key'], (int) $row['revision'], $this->nullableDate($row['submitted_at'] ?? null), $this->nullableDate($row['applied_at'] ?? null), $target, (string) $row['entity_type'], $this->nullableDate($row['created_at'] ?? null), $this->nullableDate($row['updated_at'] ?? null), $this->nullableDate($row['cancelled_at'] ?? null), $this->nullableDate($row['rejected_at'] ?? null), $this->nullableDate($row['superseded_at'] ?? null), $supersededBy);
        } catch (\InvalidArgumentException|\JsonException) {
            return null;
        }
    }
    public function create(Proposal $proposal): Proposal {
        ProposalSubjectBindingValidator::assertValid($proposal);
        $db = $this->db();
        $existing = $this->findByIdempotencyKey($proposal->idempotencyKey);
        if ($existing !== null) {
            if ($this->sameIdempotentContent($existing, $proposal)) return $existing;
            throw new \NHK\Core\Governance\Exception\ProposalIdempotencyConflict('Idempotency key is already bound to different content.');
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $insertQuery = $db->prepare('INSERT INTO '.$this->table().' (proposal_uuid,idempotency_key,operation,entity_type,subject_id,target_uuid,expected_revision,command_json,fingerprint,dependency_fingerprint,state,revision,created_by,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,%s,%s)', UuidCodec::toBinary($proposal->id), $proposal->idempotencyKey, $proposal->operation, $proposal->entityType ?: $proposal->subjectId, $proposal->subjectId, $proposal->targetUuid ? UuidCodec::toBinary($proposal->targetUuid) : null, $proposal->expectedRevision, wp_json_encode($proposal->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->fingerprintBinary($proposal->contentFingerprint), $this->fingerprintBinary($proposal->dependencyFingerprint), $this->state($proposal->state), $proposal->revision, (int) ($proposal->actor ?? 0), $now, $now);
        $ok = $db->query($insertQuery);
        $insertLastError = (string) $db->last_error;
        $insertLastQuery = (string) ($db->last_query ?? $insertQuery);
        $insertAffectedRows = (string) ($db->rows_affected ?? 'unknown');
        $insertId = (string) ($db->insert_id ?? 'unknown');
        if ($ok === false || $ok !== 1) {
            // The unique idempotency index is the race-safe serialization point.
            $existing = $this->findByIdempotencyKey($proposal->idempotencyKey);
            if ($existing) {
                if ($this->sameIdempotentContent($existing, $proposal)) return $existing;
                throw new \NHK\Core\Governance\Exception\ProposalIdempotencyConflict('Idempotency key is already bound to different content.');
            }
            throw new \RuntimeException(sprintf(
                'PROPOSAL_INSERT_FAILED: table=%s; insert_result=%s; affected_rows=%s; insert_id=%s; last_error=%s; last_query=%s',
                $this->table(),
                var_export($ok, true),
                $insertAffectedRows,
                $insertId,
                $insertLastError,
                $insertLastQuery,
            ));
        }
        $readback = $this->find($proposal->id);
        if ($readback !== null) return $readback;
        $readbackQuery = $db->prepare('SELECT proposal_uuid FROM '.$this->table().' WHERE proposal_uuid=%s LIMIT 1', UuidCodec::toBinary($proposal->id));
        $canonicalRow = $db->get_row($readbackQuery, ARRAY_A);
        throw new \RuntimeException(sprintf(
            'PROPOSAL_READBACK_FAILED: table=%s; insert_result=%s; affected_rows=%s; insert_id=%s; canonical_row_present=%s; canonical_query=%s; last_error=%s',
            $this->table(),
            var_export($ok, true),
            $insertAffectedRows,
            $insertId,
            $canonicalRow === null ? '0' : '1',
            $readbackQuery,
            (string) $db->last_error,
        ));
    }
    public function find(string $id): ?Proposal { $db=$this->db(); return $this->hydrate($db->get_row($db->prepare('SELECT * FROM '.$this->table().' WHERE proposal_uuid=%s LIMIT 1',UuidCodec::toBinary($id)),ARRAY_A)); }
    /** @return list<Proposal> */
    public function listRecent(int $limit = 50, ?ProposalState $state = null): array
    {
        $db = $this->db();
        $limit = min(100, max(1, $limit));
        $where = $state === null ? '' : $db->prepare(' WHERE state=%d', $this->state($state));
        $rows = $db->get_results('SELECT * FROM ' . $this->table() . $where . ' ORDER BY id DESC LIMIT ' . $limit, ARRAY_A) ?: [];
        return array_values(array_filter(array_map(fn (array $row): ?Proposal => $this->hydrate($row), $rows), static fn (?Proposal $proposal): bool => $proposal !== null));
    }
    public function findByIdempotencyKey(string $key): ?Proposal
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare('SELECT * FROM '.$this->table().' WHERE idempotency_key=%s LIMIT 1', $key), ARRAY_A);
        if (!$row) return null;
        $proposal = $this->hydrate($row);
        if ($proposal === null) {
            throw new ProposalIdempotencyStaleBinding('IDEMPOTENCY_STALE_BINDING: existing idempotency binding is not readable from the canonical proposal store.');
        }
        // A governed repair preserves the original command and records the
        // replacement through supersession. Resolve the old key to that
        // replacement for replay, without mutating the original audit row.
        if ($proposal->state === ProposalState::SUPERSEDED && UuidCodec::isValid((string) ($proposal->supersededByProposalId ?? ''))) {
            $replacement = $this->find((string) $proposal->supersededByProposalId);
            if ($replacement !== null) return $replacement;
        }
        return $proposal;
    }
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

    public function findLatestVideoIngest(string $videoId): ?Proposal
    {
        if (!UuidCodec::isValid($videoId)) return null;
        $db = $this->db();
        $rows = $db->get_results($db->prepare('SELECT * FROM '.$this->table().' WHERE entity_type=%s AND operation=%s ORDER BY id DESC', 'video', 'ingest'), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $proposal = $this->hydrate($row);
            if ($proposal !== null && (string) ($proposal->payload['canonical_id'] ?? '') === $videoId) return $proposal;
        }
        return null;
    }

    public function findApprovedFingerprintBoundRelations(string $sourceType, string $sourceUuid, string $sourceFingerprint): array
    {
        if ($sourceType !== 'video' || !UuidCodec::isValid($sourceUuid)) return [];
        $db = $this->db();
        $rows = $db->get_results($db->prepare(
            'SELECT * FROM '.$this->table().' WHERE entity_type=%s AND operation=%s AND state=%d ORDER BY id ASC',
            'relation', 'relation_create', $this->state(ProposalState::APPROVED)
        ), ARRAY_A) ?: [];
        $matches = [];
        foreach ($rows as $row) {
            $proposal = $this->hydrate($row);
            if ($proposal === null || $proposal->state !== ProposalState::APPROVED) continue;
            $payload = $proposal->payload;
            if (($payload['source_type'] ?? '') !== $sourceType || ($payload['source_uuid'] ?? '') !== $sourceUuid) continue;
            if ($sourceFingerprint !== '' && isset($payload['source_fingerprint']) && (string) $payload['source_fingerprint'] !== $sourceFingerprint) continue;
            $approval = $this->latestApproval($proposal->id);
            if ($approval === null || (int) ($approval['proposal_revision'] ?? 0) !== $proposal->revision || !hash_equals((string) ($approval['fingerprint'] ?? ''), $this->fingerprintBinary($proposal->bindingFingerprint()))) continue;
            $matches[] = $proposal;
        }
        return $matches;
    }
}
