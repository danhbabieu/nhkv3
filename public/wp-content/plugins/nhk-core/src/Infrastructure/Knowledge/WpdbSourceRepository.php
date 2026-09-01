<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Knowledge;

use NHK\Core\Contracts\Knowledge\SourceRepository;
use NHK\Core\Domain\Knowledge\{Source, KnowledgeException};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbSourceRepository implements SourceRepository
{
    private string $table;
    public function __construct(private object $database) { $this->table = $database->prefix . 'nhk_sources'; }
    public function findByCanonicalId(string $id): ?Source { return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE canonical_uuid=%s LIMIT 1", UuidCodec::toBinary($id)), ARRAY_A)); }
    public function findByStableKey(string $key): ?Source { return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE stable_key=%s LIMIT 1", $key), ARRAY_A)); }
    public function create(Source $source): Source
    {
        $existingById = $this->findByCanonicalId($source->canonicalId);
        if ($existingById !== null) {
            if ($this->sameSource($existingById, $source)) return $existingById;
            throw new KnowledgeException('Source identity already exists.');
        }
        $existingByKey = $this->findByStableKey($source->stableKey);
        if ($existingByKey !== null) {
            if ($this->sameSource($existingByKey, $source)) return $existingByKey;
            throw new KnowledgeException('Source identity already exists.');
        }
        $locatorSql = $source->locator === null ? 'NULL' : '%s';
        $args = [UuidCodec::toBinary($source->canonicalId), $source->stableKey, $source->title, $source->sourceType];
        if ($source->locator !== null) $args[] = $source->locator;
        $args[] = wp_json_encode($source->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        array_push($args, $source->active ? 1 : 0, $source->revision, gmdate('Y-m-d H:i:s.u'), gmdate('Y-m-d H:i:s.u'));
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (canonical_uuid,stable_key,title,source_type,locator,metadata_json,state,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,{$locatorSql},%s,%d,%d,%s,%s)", ...$args));
        if ($ok === false) { $existing = $this->findByStableKey($source->stableKey); if ($existing && $this->sameSource($existing, $source)) return $existing; throw new KnowledgeException('Source identity already exists.'); }
        return $this->findByCanonicalId($source->canonicalId) ?? $source;
    }
    public function update(Source $source, int $expectedRevision): Source
    {
        $locatorSql = $source->locator === null ? 'NULL' : '%s';
        $args = [$source->title, $source->sourceType, wp_json_encode($source->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $source->active ? 1 : 0, gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($source->canonicalId), $expectedRevision];
        if ($source->locator !== null) array_splice($args, 2, 0, [$source->locator]);
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->table} SET title=%s,source_type=%s,locator={$locatorSql},metadata_json=%s,state=%d,revision=revision+1,updated_at=%s WHERE canonical_uuid=%s AND revision=%d", ...$args));
        if ($ok !== 1) throw new KnowledgeException('Source revision conflict.');
        return $this->findByCanonicalId($source->canonicalId) ?? $source;
    }
    public function list(bool $includeRetired = false): array { $rows = $this->database->get_results("SELECT * FROM {$this->table}" . ($includeRetired ? '' : ' WHERE state=1') . ' ORDER BY id', ARRAY_A); return array_values(array_filter(array_map(fn (array $row): ?Source => $this->hydrate($row), $rows ?: []), static fn (?Source $source): bool => $source !== null)); }
    private function hydrate(?array $row): ?Source { if (!$row) return null; $metadata = (string) ($row['metadata_json'] ?? ''); if ($metadata === '') { $decodedMetadata = []; } else { try { $decodedMetadata = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { return null; } if (!is_array($decodedMetadata)) return null; } return new Source(UuidCodec::fromBinary($row['canonical_uuid']), (string) $row['stable_key'], (string) $row['title'], (string) $row['source_type'], $row['locator'] === null ? null : (string) $row['locator'], $decodedMetadata, (int) $row['state'] === 1, (int) $row['revision']); }

    private function sameSource(Source $left, Source $right): bool
    {
        return $left->stableKey === $right->stableKey
            && $left->title === $right->title
            && $left->sourceType === $right->sourceType
            && $left->locator === $right->locator
            && $left->metadata === $right->metadata
            && $left->active === $right->active
            && $left->revision === $right->revision;
    }
}
