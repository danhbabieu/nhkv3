<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Knowledge;

use NHK\Core\Contracts\Knowledge\EvidenceRepository;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeException};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbEvidenceRepository implements EvidenceRepository
{
    private string $table;
    public function __construct(private object $database) { $this->table = $database->prefix . 'nhk_evidence'; }
    public function findByCanonicalId(string $id): ?Evidence { return $this->findById($id); }
    public function create(Evidence $evidence): Evidence
    {
        $locatorSql = $evidence->locator === null ? 'NULL' : '%s';
        $args = [UuidCodec::toBinary($evidence->canonicalId), UuidCodec::toBinary($evidence->claimId), UuidCodec::toBinary($evidence->sourceId), $evidence->relation, $evidence->excerpt];
        if ($evidence->locator !== null) $args[] = $evidence->locator;
        $args[] = wp_json_encode($evidence->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        array_push($args, $evidence->active ? 1 : 0, $evidence->revision, gmdate('Y-m-d H:i:s.u'), gmdate('Y-m-d H:i:s.u'));
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (evidence_uuid,claim_uuid,source_uuid,relation_type,excerpt,locator,metadata_json,state,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,{$locatorSql},%s,%d,%d,%s,%s)", ...$args));
        if ($ok === false) throw new KnowledgeException('Evidence identity already exists.');
        return $this->findById($evidence->canonicalId) ?? $evidence;
    }
    public function update(Evidence $evidence, int $expectedRevision): Evidence
    {
        $locatorSql = $evidence->locator === null ? 'NULL' : '%s';
        $args = [$evidence->relation, $evidence->excerpt];
        if ($evidence->locator !== null) $args[] = $evidence->locator;
        array_push($args, wp_json_encode($evidence->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $evidence->active ? 1 : 0, gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($evidence->canonicalId), $expectedRevision);
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->table} SET relation_type=%s,excerpt=%s,locator={$locatorSql},metadata_json=%s,state=%d,revision=revision+1,updated_at=%s WHERE evidence_uuid=%s AND revision=%d", ...$args));
        if ($ok !== 1) throw new KnowledgeException('Evidence revision conflict.');
        return $this->findById($evidence->canonicalId) ?? $evidence;
    }
    public function listByClaim(string $claimId, bool $includeRetired = false): array { return $this->list('claim_uuid', $claimId, $includeRetired); }
    public function listBySource(string $sourceId, bool $includeRetired = false): array { return $this->list('source_uuid', $sourceId, $includeRetired); }
    private function list(string $column, string $id, bool $includeRetired): array { $state = $includeRetired ? '' : ' AND state=1'; $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE {$column}=%s{$state} ORDER BY id", UuidCodec::toBinary($id)), ARRAY_A); return array_map(fn (array $row): Evidence => $this->hydrate($row), $rows ?: []); }
    private function findById(string $id): ?Evidence { return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE evidence_uuid=%s LIMIT 1", UuidCodec::toBinary($id)), ARRAY_A)); }
    private function hydrate(?array $row): ?Evidence { if (!$row) return null; $metadata = (string) ($row['metadata_json'] ?? ''); return new Evidence(UuidCodec::fromBinary($row['evidence_uuid']), UuidCodec::fromBinary($row['claim_uuid']), UuidCodec::fromBinary($row['source_uuid']), (string) $row['relation_type'], (string) $row['excerpt'], $row['locator'] === null ? null : (string) $row['locator'], (int) $row['state'] === 1, (int) $row['revision'], $metadata === '' ? [] : json_decode($metadata, true, 512, JSON_THROW_ON_ERROR)); }
}
