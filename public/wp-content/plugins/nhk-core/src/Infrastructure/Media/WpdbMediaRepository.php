<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Domain\Media\{Media, MediaException};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbMediaRepository implements MediaRepository
{
    private string $table;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_media';
    }

    public function findByCanonicalId(string $id): ?Media
    {
        return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE canonical_uuid=%s LIMIT 1", UuidCodec::toBinary($id)), ARRAY_A));
    }

    public function findByStableKey(string $stableKey): ?Media
    {
        return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE stable_key=%s LIMIT 1", $stableKey), ARRAY_A));
    }

    public function create(Media $media): Media
    {
        $existingById = $this->findByCanonicalId($media->canonicalId);
        if ($existingById !== null) {
            if ($this->sameMedia($existingById, $media)) return $existingById;
            throw new MediaException('Media stable key or identity already exists.');
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (canonical_uuid,stable_key,canonical_name,readiness,provenance_json,state,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%d,%d,%s,%s)", UuidCodec::toBinary($media->canonicalId), $media->stableKey, $media->canonicalName, $media->readiness, wp_json_encode($media->provenance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $media->active ? 1 : 0, $media->revision, $now, $now));
        if ($ok === false) {
            $existing = $this->findByStableKey($media->stableKey);
            if ($existing && $this->sameMedia($existing, $media)) return $existing;
            throw new MediaException('Media stable key or identity already exists.');
        }
        return $this->findByCanonicalId($media->canonicalId) ?? $media;
    }

    public function update(Media $media, int $expectedRevision): Media
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->table} SET canonical_name=%s,readiness=%s,provenance_json=%s,state=%d,revision=revision+1,updated_at=%s WHERE canonical_uuid=%s AND revision=%d", $media->canonicalName, $media->readiness, wp_json_encode($media->provenance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $media->active ? 1 : 0, $now, UuidCodec::toBinary($media->canonicalId), $expectedRevision));
        if ($ok !== 1) throw new MediaException('Media revision conflict.');
        return $this->findByCanonicalId($media->canonicalId) ?? $media;
    }

    public function list(bool $includeRetired = false): array
    {
        $state = $includeRetired ? '' : ' WHERE state=1';
        $rows = $this->database->get_results("SELECT * FROM {$this->table}{$state} ORDER BY id", ARRAY_A);
        return array_map(fn (array $row): Media => $this->hydrate($row), $rows ?: []);
    }

    private function hydrate(?array $row): ?Media
    {
        if (!$row) return null;
        return new Media(UuidCodec::fromBinary($row['canonical_uuid']), (string) $row['stable_key'], (string) $row['canonical_name'], (string) $row['readiness'], json_decode((string) $row['provenance_json'], true, 512, JSON_THROW_ON_ERROR), (int) $row['state'] === 1, (int) $row['revision']);
    }

    private function sameMedia(Media $left, Media $right): bool
    {
        return $left->canonicalId === $right->canonicalId
            && $left->stableKey === $right->stableKey
            && $left->canonicalName === $right->canonicalName
            && $left->readiness === $right->readiness
            && $left->provenance === $right->provenance
            && $left->active === $right->active
            && $left->revision === $right->revision;
    }
}
