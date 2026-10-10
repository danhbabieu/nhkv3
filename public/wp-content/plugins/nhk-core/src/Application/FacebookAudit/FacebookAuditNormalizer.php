<?php
declare(strict_types=1);

namespace NHK\Core\Application\FacebookAudit;

use NHK\Core\Domain\FacebookAudit\FacebookValue;

final class FacebookAuditNormalizer
{
    /** @return array<string,mixed> */
    public function post(array $row, string $source, ?string $targetPageId = null): array
    {
        $normalized = [
            'post_id' => $this->stringOrNull($row['post_id'] ?? null),
            'post_url' => $this->stringOrNull($row['post_url'] ?? null),
            'published_at' => $this->stringOrNull($row['published_at'] ?? null),
            'content_type' => strtoupper($this->stringOrNull($row['content_type'] ?? null) ?? 'UNKNOWN'),
            'text' => $this->stringOrNull($row['text'] ?? null),
            'media_references' => array_values(array_map('strval', array_filter((array) ($row['media_references'] ?? []), static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== ''))),
            'reaction_count' => $this->value($row, 'reaction_count'),
            'comment_count' => $this->value($row, 'comment_count'),
            'share_count' => $this->value($row, 'share_count'),
            'video_views' => $this->value($row, 'video_views'),
            'source' => strtoupper(trim($source)),
            'collection_timestamp' => gmdate('c'),
        ];
        if ($source === 'GROUP') {
            $normalized['group_id'] = $this->stringOrNull($row['group_id'] ?? null);
            $normalized['group_url'] = $this->stringOrNull($row['group_url'] ?? null);
            $normalized['group_post_kind'] = strtoupper($this->stringOrNull($row['group_post_kind'] ?? null) ?? 'INSUFFICIENT_DATA');
            $normalized['page_author_id'] = $this->stringOrNull($row['page_author_id'] ?? null);
            $normalized['page_author_verified'] = ($row['page_author_verified'] ?? false) === true;
            if ($targetPageId !== null && $normalized['page_author_id'] !== $targetPageId) $normalized['group_post_kind'] = 'THIRD_PARTY_SHARED';
        }
        return $normalized;
    }

    /** @param array<string,mixed> $row */
    private function value(array $row, string $key): FacebookValue
    {
        if (!array_key_exists($key, $row)) return FacebookValue::unavailable();
        $value = $row[$key];
        if (is_array($value) && isset($value['state'])) {
            return match (strtoupper((string) $value['state'])) {
                FacebookValue::NULL => FacebookValue::nullValue(),
                FacebookValue::UNAVAILABLE => FacebookValue::unavailable(),
                FacebookValue::INACCESSIBLE => FacebookValue::inaccessible(),
                FacebookValue::KNOWN => FacebookValue::known($value['value'] ?? null),
                default => FacebookValue::unavailable(),
            };
        }
        return $value === null ? FacebookValue::nullValue() : FacebookValue::known($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
