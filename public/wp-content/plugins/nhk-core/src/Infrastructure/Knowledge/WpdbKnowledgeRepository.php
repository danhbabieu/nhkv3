<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Knowledge;

use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Domain\Knowledge\{KnowledgeClaim, KnowledgeException};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbKnowledgeRepository implements KnowledgeRepository
{
    private string $table;
    public function __construct(private object $database) { $this->table = $database->prefix . 'nhk_knowledge_claims'; }
    public function findByCanonicalId(string $id): ?KnowledgeClaim { return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE canonical_uuid=%s LIMIT 1", UuidCodec::toBinary($id)), ARRAY_A)); }
    public function findByStableKey(string $key): ?KnowledgeClaim { return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE stable_key=%s LIMIT 1", $key), ARRAY_A)); }
    public function create(KnowledgeClaim $claim): KnowledgeClaim
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (canonical_uuid,stable_key,claim_text,claim_type,provenance_json,state,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%d,%d,%s,%s)", UuidCodec::toBinary($claim->canonicalId), $claim->stableKey, $claim->claimText, $claim->claimType, wp_json_encode($claim->provenance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $claim->active ? 1 : 0, $claim->revision, $now, $now));
        if ($ok === false) { $existing = $this->findByStableKey($claim->stableKey); if ($existing && $existing->canonicalId === $claim->canonicalId && $existing->claimText === $claim->claimText) return $existing; throw new KnowledgeException('Knowledge claim identity already exists.'); }
        return $this->findByCanonicalId($claim->canonicalId) ?? $claim;
    }
    public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim
    {
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->table} SET claim_text=%s,claim_type=%s,provenance_json=%s,state=%d,revision=revision+1,updated_at=%s WHERE canonical_uuid=%s AND revision=%d", $claim->claimText, $claim->claimType, wp_json_encode($claim->provenance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $claim->active ? 1 : 0, gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($claim->canonicalId), $expectedRevision));
        if ($ok !== 1) throw new KnowledgeException('Knowledge claim revision conflict.');
        return $this->findByCanonicalId($claim->canonicalId) ?? $claim;
    }
    public function list(bool $includeRetired = false): array { $rows = $this->database->get_results("SELECT * FROM {$this->table}" . ($includeRetired ? '' : ' WHERE state=1') . ' ORDER BY id', ARRAY_A); return array_map(fn (array $row): KnowledgeClaim => $this->hydrate($row), $rows ?: []); }
    private function hydrate(?array $row): ?KnowledgeClaim { if (!$row) return null; return new KnowledgeClaim(UuidCodec::fromBinary($row['canonical_uuid']), (string) $row['stable_key'], (string) $row['claim_text'], (string) $row['claim_type'], json_decode((string) $row['provenance_json'], true, 512, JSON_THROW_ON_ERROR), (int) $row['state'] === 1, (int) $row['revision']); }
}
