<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\ArticleMediaUsageInventory;
use NHK\Core\Domain\Media\MediaUsage;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Read-only, stable-UUID cursor inventory for Article Usage rows. */
final class WpdbArticleMediaUsageInventory implements ArticleMediaUsageInventory
{
    private string $table;
    private string $mediaTable;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_media_usages';
        $this->mediaTable = $database->prefix . 'nhk_media';
    }

    public function page(string $endpointType, ?string $afterUsageId, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $where = 'endpoint_type=%s';
        $args = [$endpointType];
        if (is_string($afterUsageId) && trim($afterUsageId) !== '') {
            $where .= ' AND usage_uuid>%s';
            $args[] = UuidCodec::toBinary(trim($afterUsageId));
        }
        $args[] = $limit + 1;
        $rows = $this->database->get_results($this->database->prepare("SELECT u.*, m.canonical_uuid AS media_uuid FROM {$this->table} u INNER JOIN {$this->mediaTable} m ON m.id=u.media_id WHERE {$where} ORDER BY u.usage_uuid LIMIT %d", ...$args), ARRAY_A);
        $rows = is_array($rows) ? array_values($rows) : [];
        $items = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $usage = $this->hydrate($row);
            if ($usage !== null) $items[] = $usage;
        }
        $hasMore = count($rows) > $limit;
        $nextCursor = null;
        if ($hasMore && isset($rows[$limit - 1]['usage_uuid'])) {
            try { $nextCursor = UuidCodec::fromBinary((string) $rows[$limit - 1]['usage_uuid']); } catch (\Throwable) { $nextCursor = null; }
        }
        return ['items' => $items, 'next_cursor' => $nextCursor];
    }

    private function hydrate(array $row): ?MediaUsage
    {
        try {
            $groups = json_decode((string) ($row['keyword_groups_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($groups) || !array_is_list($groups) || !isset($row['media_uuid']) || strlen((string) $row['media_uuid']) !== 16) return null;
            return new MediaUsage(UuidCodec::fromBinary($row['usage_uuid']), UuidCodec::fromBinary($row['media_uuid']), (string) $row['endpoint_type'], (string) $row['endpoint_key'], (string) $row['usage_role'], (int) ($row['sort_order'] ?? 0), (string) ($row['alt_text'] ?? ''), (string) ($row['caption'] ?? ''), array_values(array_map('strval', $groups)), (string) ($row['title'] ?? ''), (int) ($row['revision'] ?? 1), (string) ($row['placement_key'] ?? ''), (string) ($row['selection_source'] ?? 'SYSTEM_AUTO'), (string) ($row['selection_policy'] ?? 'AUTO'), isset($row['active_slot']) && $row['active_slot'] !== '' ? (string) $row['active_slot'] : null);
        } catch (\Throwable) {
            return null;
        }
    }
}
