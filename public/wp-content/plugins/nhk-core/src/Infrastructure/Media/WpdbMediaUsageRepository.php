<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\MutableMediaUsageRepository;
use NHK\Core\Domain\Media\{MediaException, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbMediaUsageRepository implements MutableMediaUsageRepository
{
    private string $table;
    private string $mediaTable;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_media_usages';
        $this->mediaTable = $database->prefix . 'nhk_media';
    }

    public function create(MediaUsage $usage): MediaUsage
    {
        $mediaId = $this->mediaInternalId($usage->mediaId);
        if ($mediaId === null) throw new MediaException('Media parent not found.');
        $existingByIdentity = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE media_id=%d AND endpoint_type=%s AND endpoint_key=%s AND usage_role=%s LIMIT 1", $mediaId, $usage->endpointType, $usage->endpointKey, $usage->role), ARRAY_A);
        if (is_array($existingByIdentity)) {
            $existing = $this->hydrate($existingByIdentity);
            if ($existing === null) throw new MediaException('Media usage row is invalid.');
            if ($existing->sortOrder === $usage->sortOrder) return $existing;
            throw new MediaException('Media usage identity already exists.');
        }
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (usage_uuid,media_id,endpoint_type,endpoint_key,usage_role,sort_order,alt_text,caption,keyword_groups_json,created_at) VALUES (%s,%d,%s,%s,%s,%d,%s,%s,%s,%s)", UuidCodec::toBinary($usage->usageId), $mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder, $usage->altText, $usage->caption, wp_json_encode($usage->keywordGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), gmdate('Y-m-d H:i:s.u')));
        if ($ok === false) {
            $existing = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE media_id=%d AND endpoint_type=%s AND endpoint_key=%s AND usage_role=%s LIMIT 1", $mediaId, $usage->endpointType, $usage->endpointKey, $usage->role), ARRAY_A);
            if (is_array($existing)) { $hydrated = $this->hydrate($existing); if ($hydrated !== null && $hydrated->sortOrder === $usage->sortOrder) return $hydrated; }
            throw new MediaException('Media usage identity already exists.');
        }
        $row = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE usage_uuid=%s LIMIT 1", UuidCodec::toBinary($usage->usageId)), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : $usage;
    }

    public function listByMediaId(string $mediaId, ?string $role = null): array
    {
        $internalId = $this->mediaInternalId($mediaId);
        if ($internalId === null) return [];
        $where = 'media_id=%d';
        $args = [$internalId];
        if ($role !== null) { $where .= ' AND usage_role=%s'; $args[] = $role; }
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE {$where} ORDER BY sort_order,id", ...$args), ARRAY_A);
        return $this->hydrateList($rows ?: []);
    }

    public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array
    {
        $where = 'endpoint_type=%s AND endpoint_key=%s';
        $args = [$endpointType, $endpointKey];
        if ($role !== null) { $where .= ' AND usage_role=%s'; $args[] = $role; }
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE {$where} ORDER BY sort_order,id", ...$args), ARRAY_A);
        return $this->hydrateList($rows ?: []);
    }

    public function removeByEndpointRole(string $endpointType, string $endpointKey, string $role): int
    {
        return max(0, (int) $this->database->query($this->database->prepare("DELETE FROM {$this->table} WHERE endpoint_type=%s AND endpoint_key=%s AND usage_role=%s", $endpointType, $endpointKey, $role)));
    }

    private function hydrate(array $row): ?MediaUsage
    {
        $mediaUuid = $this->database->get_var($this->database->prepare("SELECT canonical_uuid FROM {$this->mediaTable} WHERE id=%d LIMIT 1", (int) $row['media_id']));
        if (!is_string($mediaUuid) || strlen($mediaUuid) !== 16) return null;
        try {
            $groups = json_decode((string) ($row['keyword_groups_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($groups) || !array_is_list($groups)) return null;
            return new MediaUsage(UuidCodec::fromBinary($row['usage_uuid']), UuidCodec::fromBinary($mediaUuid), (string) $row['endpoint_type'], (string) $row['endpoint_key'], (string) $row['usage_role'], (int) $row['sort_order'], (string) ($row['alt_text'] ?? ''), (string) ($row['caption'] ?? ''), array_values(array_map('strval', $groups)));
        } catch (\Throwable) { return null; }
    }

    private function mediaInternalId(string $mediaUuid): ?int { $id = $this->database->get_var($this->database->prepare("SELECT id FROM {$this->mediaTable} WHERE canonical_uuid=%s LIMIT 1", UuidCodec::toBinary($mediaUuid))); return $id === null ? null : (int) $id; }

    /** @param list<array<string,mixed>> $rows @return list<MediaUsage> */
    private function hydrateList(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) { $usage = $this->hydrate($row); if ($usage !== null) $items[] = $usage; }
        return $items;
    }
}
