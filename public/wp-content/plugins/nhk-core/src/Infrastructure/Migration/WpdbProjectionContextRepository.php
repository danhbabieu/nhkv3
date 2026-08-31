<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Migration;

/**
 * Persistence boundary for non-public, non-canonical V2 projection context.
 * This repository accepts only the bounded metadata shape used by the mapper.
 */
final class WpdbProjectionContextRepository
{
    private string $table;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_legacy_projection_contexts';
    }

    public function findBySourceKey(string $sourceKey): ?array
    {
        return $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE source_key=%s LIMIT 1", $sourceKey), ARRAY_A) ?: null;
    }

    /** @param array<string,mixed> $context */
    public function upsert(array $context): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $provenance = wp_json_encode($context['provenance'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sql = "INSERT INTO {$this->table} (source_key,projection_id,semantic_id,canonical_object_id,canonical_object_type,projection_type,visibility,quality_state,seo_ready,ai_ready,stale,provenance_json,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE projection_id=VALUES(projection_id),semantic_id=VALUES(semantic_id),canonical_object_id=VALUES(canonical_object_id),canonical_object_type=VALUES(canonical_object_type),projection_type=VALUES(projection_type),visibility=VALUES(visibility),quality_state=VALUES(quality_state),seo_ready=VALUES(seo_ready),ai_ready=VALUES(ai_ready),stale=VALUES(stale),provenance_json=VALUES(provenance_json),updated_at=VALUES(updated_at)";
        $ok = $this->database->query($this->database->prepare($sql, (string) $context['source_key'], (string) $context['projection_id'], (string) $context['semantic_id'], (string) $context['canonical_object_id'], (string) $context['canonical_object_type'], (string) $context['projection_type'], (string) $context['visibility'], (string) $context['quality_state'], (int) $context['seo_ready'], (int) $context['ai_ready'], (int) $context['stale'], $provenance, $now, $now));
        if ($ok === false) throw new \RuntimeException('PROJECTION_CONTEXT_WRITE_FAILED');
    }
}
