<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Projection;

use NHK\Core\Contracts\Projection\ProjectionRevisionStore;
use NHK\Core\Domain\Projection\{ProjectionRevision, ProjectionStatus};

final class WpdbProjectionRevisionStore implements ProjectionRevisionStore
{
    private string $table;

    public function __construct(private object $database) { $this->table = $database->prefix . 'nhk_claim_projection_revisions'; }

    public function saveCandidate(ProjectionRevision $revision): ProjectionRevision
    {
        $existing = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE node_uuid=%s AND input_hash=%s AND status IN ('candidate','validating','ready') ORDER BY projection_revision DESC LIMIT 1", $revision->nodeUuid, $revision->inputHash), ARRAY_A);
        if (is_array($existing)) return $this->hydrate($existing);
        $next = (int) $this->database->get_var($this->database->prepare("SELECT COALESCE(MAX(projection_revision),0)+1 FROM {$this->table} WHERE node_uuid=%s", $revision->nodeUuid));
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (node_uuid,projection_revision,status,input_hash,claim_set_hash,graph_hash,policy_revision,template_revision,payload_json,dirty_sections_json,generated_at,published_at,created_at,updated_at) VALUES (%s,%d,%s,%s,%s,%s,%d,%d,%s,%s,%s,%s,%s,%s)", $revision->nodeUuid, $next, ProjectionStatus::CANDIDATE, $revision->inputHash, $revision->claimSetHash, $revision->graphHash, $revision->policyRevision, $revision->templateRevision, $this->encode($revision->payload), $this->encode($revision->dirtySections), $this->mysqlTimestamp($revision->generatedAt, $now), $revision->publishedAt !== null ? $this->mysqlTimestamp($revision->publishedAt, $now) : null, $now, $now));
        if ($ok === false) throw new \RuntimeException('PROJECTION_REVISION_WRITE_FAILED');
        return $this->findByRevision($revision->nodeUuid, $next) ?? throw new \RuntimeException('PROJECTION_REVISION_READBACK_FAILED');
    }

    public function findLatest(string $nodeUuid): ?ProjectionRevision { return $this->find("node_uuid=%s ORDER BY projection_revision DESC LIMIT 1", [$nodeUuid]); }
    public function findPublished(string $nodeUuid): ?ProjectionRevision { return $this->find("node_uuid=%s AND status='published' ORDER BY projection_revision DESC LIMIT 1", [$nodeUuid]); }
    public function findCandidate(string $nodeUuid): ?ProjectionRevision { return $this->find("node_uuid=%s AND status IN ('candidate','validating','ready') ORDER BY projection_revision DESC LIMIT 1", [$nodeUuid]); }

    public function publish(string $nodeUuid, int $revision): ProjectionRevision
    {
        $candidate = $this->findByRevision($nodeUuid, $revision);
        if ($candidate === null || $candidate->status !== ProjectionStatus::READY) throw new \RuntimeException('PROJECTION_NOT_READY');
        $this->database->query('START TRANSACTION');
        try {
            $now = gmdate('Y-m-d H:i:s.u');
            $this->database->query($this->database->prepare("UPDATE {$this->table} SET status='superseded',updated_at=%s WHERE node_uuid=%s AND status='published'", $now, $nodeUuid));
            $ok = $this->database->query($this->database->prepare("UPDATE {$this->table} SET status='published',dirty_sections_json=%s,published_at=%s,updated_at=%s WHERE node_uuid=%s AND projection_revision=%d AND status='ready'", $this->encode([]), $now, $now, $nodeUuid, $revision));
            if ($ok !== 1) throw new \RuntimeException('PROJECTION_PUBLISH_CAS_FAILED');
            $this->database->query('COMMIT');
        } catch (\Throwable $e) { $this->database->query('ROLLBACK'); throw $e; }
        return $this->findByRevision($nodeUuid, $revision) ?? throw new \RuntimeException('PROJECTION_PUBLISH_READBACK_FAILED');
    }

    public function discard(string $nodeUuid, int $revision): void { $this->database->query($this->database->prepare("DELETE FROM {$this->table} WHERE node_uuid=%s AND projection_revision=%d AND status IN ('candidate','validating','ready')", $nodeUuid, $revision)); }

    public function markDirty(string $nodeUuid, array $sections): void
    {
        $candidate = $this->findCandidate($nodeUuid); if ($candidate === null) return;
        $dirty = array_values(array_unique(array_merge($candidate->dirtySections, $sections)));
        $this->database->query($this->database->prepare("UPDATE {$this->table} SET status='candidate',dirty_sections_json=%s,updated_at=%s WHERE node_uuid=%s AND projection_revision=%d", $this->encode($dirty), gmdate('Y-m-d H:i:s.u'), $nodeUuid, $candidate->revision));
    }

    public function markReady(string $nodeUuid, int $revision): ProjectionRevision
    {
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->table} SET status='ready',updated_at=%s WHERE node_uuid=%s AND projection_revision=%d AND status='candidate'", gmdate('Y-m-d H:i:s.u'), $nodeUuid, $revision));
        if ($ok !== 1) throw new \RuntimeException('PROJECTION_CANDIDATE_NOT_FOUND');
        return $this->findByRevision($nodeUuid, $revision) ?? throw new \RuntimeException('PROJECTION_READY_READBACK_FAILED');
    }

    private function find(string $where, array $args): ?ProjectionRevision { $row = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE {$where}", ...$args), ARRAY_A); return is_array($row) ? $this->hydrate($row) : null; }
    private function findByRevision(string $nodeUuid, int $revision): ?ProjectionRevision { return $this->find('node_uuid=%s AND projection_revision=%d LIMIT 1', [$nodeUuid, $revision]); }
    private function hydrate(array $row): ProjectionRevision
    {
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true); $dirty = json_decode((string) ($row['dirty_sections_json'] ?? ''), true);
        return new ProjectionRevision((string) $row['node_uuid'], (int) $row['projection_revision'], (string) $row['status'], (string) $row['input_hash'], (string) $row['claim_set_hash'], (string) $row['graph_hash'], (int) $row['policy_revision'], (int) $row['template_revision'], is_array($payload) ? $payload : [], is_array($dirty) ? $dirty : [], (string) ($row['generated_at'] ?? ''), ($row['published_at'] ?? null) !== null ? (string) $row['published_at'] : null);
    }
    private function encode(mixed $value): string { return function_exists('wp_json_encode') ? (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    private function mysqlTimestamp(string $value, string $fallback): string { $timestamp = strtotime($value); return $timestamp === false ? $fallback : gmdate('Y-m-d H:i:s.u', $timestamp); }
}
