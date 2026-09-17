<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\{MediaUsageUpdater, MutableMediaUsageRepository};
use NHK\Core\Domain\Media\{MediaException, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbMediaUsageRepository implements MutableMediaUsageRepository, MediaUsageUpdater
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
        $existingByIdentity = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE media_id=%d AND endpoint_type=%s AND endpoint_key=%s AND usage_role=%s AND placement_key=%s LIMIT 1", $mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->placementKey), ARRAY_A);
        if (is_array($existingByIdentity)) {
            $existing = $this->hydrate($existingByIdentity);
            if ($existing === null) throw new MediaException('Media usage row is invalid.');
            if ($this->sameUsage($existing, $usage)) return $existing;
            return $this->update(new MediaUsage($existing->usageId, $usage->mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder, $usage->altText, $usage->caption, $usage->keywordGroups, $usage->title, $existing->revision, $usage->placementKey, $usage->selectionSource, $usage->selectionPolicy, $usage->activeSlot));
        }
        $activeSlot = $usage->activeSlot === null ? 'NULL' : $this->database->prepare('%s', $usage->activeSlot);
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (usage_uuid,media_id,endpoint_type,endpoint_key,usage_role,placement_key,active_slot,selection_source,selection_policy,sort_order,alt_text,caption,title,keyword_groups_json,revision,created_at) VALUES (%s,%d,%s,%s,%s,%s,{$activeSlot},%s,%s,%d,%s,%s,%s,%s,%d,%s)", UuidCodec::toBinary($usage->usageId), $mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->placementKey, $usage->selectionSource, $usage->selectionPolicy, $usage->sortOrder, $usage->altText, $usage->caption, $usage->title, wp_json_encode($usage->keywordGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $usage->revision, gmdate('Y-m-d H:i:s.u')));
        if ($ok === false) {
            $existing = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE media_id=%d AND endpoint_type=%s AND endpoint_key=%s AND usage_role=%s AND placement_key=%s LIMIT 1", $mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->placementKey), ARRAY_A);
            if (is_array($existing)) {
                $hydrated = $this->hydrate($existing);
                if ($hydrated === null) throw new MediaException('Media usage row is invalid.');
                if ($this->sameUsage($hydrated, $usage)) return $hydrated;
                return $this->update(new MediaUsage($hydrated->usageId, $usage->mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder, $usage->altText, $usage->caption, $usage->keywordGroups, $usage->title, $hydrated->revision, $usage->placementKey, $usage->selectionSource, $usage->selectionPolicy, $usage->activeSlot));
            }
            throw new MediaException('Media usage create failed.');
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

    public function update(MediaUsage $usage): MediaUsage
    {
        $mediaId = $this->mediaInternalId($usage->mediaId);
        if ($mediaId === null) throw new MediaException('Media parent not found.');
        $existing = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE usage_uuid=%s LIMIT 1", UuidCodec::toBinary($usage->usageId)), ARRAY_A);
        if (!is_array($existing)) throw new MediaException('Media usage not found.');
        $nextRevision = $usage->revision + 1;
        $activeSlot = $usage->activeSlot === null ? 'NULL' : $this->database->prepare('%s', $usage->activeSlot);
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->table} SET media_id=%d,endpoint_type=%s,endpoint_key=%s,usage_role=%s,placement_key=%s,active_slot={$activeSlot},selection_source=%s,selection_policy=%s,sort_order=%d,alt_text=%s,caption=%s,title=%s,keyword_groups_json=%s,revision=%d WHERE usage_uuid=%s AND revision=%d", $mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->placementKey, $usage->selectionSource, $usage->selectionPolicy, $usage->sortOrder, $usage->altText, $usage->caption, $usage->title, wp_json_encode($usage->keywordGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $nextRevision, UuidCodec::toBinary($usage->usageId), $usage->revision));
        if ($ok !== 1) throw new MediaException('Media usage update conflict.');
        $row = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE usage_uuid=%s LIMIT 1", UuidCodec::toBinary($usage->usageId)), ARRAY_A);
        $readback = is_array($row) ? $this->hydrate($row) : null;
        if (!$readback instanceof MediaUsage) throw new MediaException('Media usage read-back failed.');
        return $readback;
    }

    public function removeByEndpointRole(string $endpointType, string $endpointKey, string $role): int
    {
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE endpoint_type=%s AND endpoint_key=%s AND usage_role=%s ORDER BY id", $endpointType, $endpointKey, $role), ARRAY_A);
        $retired = 0;
        foreach ($rows ?: [] as $row) {
            $usage = is_array($row) ? $this->hydrate($row) : null;
            if (!$usage instanceof MediaUsage || $usage->activeSlot === 'retired') continue;
            $this->update(new MediaUsage(
                $usage->usageId,
                $usage->mediaId,
                $usage->endpointType,
                $usage->endpointKey,
                $usage->role,
                $usage->sortOrder,
                $usage->altText,
                $usage->caption,
                $usage->keywordGroups,
                $usage->title,
                $usage->revision,
                $usage->placementKey,
                $usage->selectionSource,
                $usage->selectionPolicy,
                'retired',
            ));
            $retired++;
        }
        return $retired;
    }

    private function hydrate(array $row): ?MediaUsage
    {
        $mediaUuid = $this->database->get_var($this->database->prepare("SELECT canonical_uuid FROM {$this->mediaTable} WHERE id=%d LIMIT 1", (int) $row['media_id']));
        if (!is_string($mediaUuid) || strlen($mediaUuid) !== 16) return null;
        try {
            $groups = json_decode((string) ($row['keyword_groups_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($groups) || !array_is_list($groups)) return null;
            return new MediaUsage(UuidCodec::fromBinary($row['usage_uuid']), UuidCodec::fromBinary($mediaUuid), (string) $row['endpoint_type'], (string) $row['endpoint_key'], (string) $row['usage_role'], (int) $row['sort_order'], (string) ($row['alt_text'] ?? ''), (string) ($row['caption'] ?? ''), array_values(array_map('strval', $groups)), (string) ($row['title'] ?? ''), (int) ($row['revision'] ?? 1), (string) ($row['placement_key'] ?? ''), (string) ($row['selection_source'] ?? 'SYSTEM_AUTO'), (string) ($row['selection_policy'] ?? 'AUTO'), isset($row['active_slot']) && $row['active_slot'] !== '' ? (string) $row['active_slot'] : null);
        } catch (\Throwable) { return null; }
    }

    private function mediaInternalId(string $mediaUuid): ?int { $id = $this->database->get_var($this->database->prepare("SELECT id FROM {$this->mediaTable} WHERE canonical_uuid=%s LIMIT 1", UuidCodec::toBinary($mediaUuid))); return $id === null ? null : (int) $id; }

    private function sameUsage(MediaUsage $left, MediaUsage $right): bool
    {
        return $left->mediaId === $right->mediaId
            && $left->endpointType === $right->endpointType
            && $left->endpointKey === $right->endpointKey
            && $left->role === $right->role
            && $left->sortOrder === $right->sortOrder
            && $left->altText === $right->altText
            && $left->caption === $right->caption
            && $left->title === $right->title
            && $left->keywordGroups === $right->keywordGroups
            && $left->placementKey === $right->placementKey
            && $left->activeSlot === $right->activeSlot
            && $left->selectionSource === $right->selectionSource
            && $left->selectionPolicy === $right->selectionPolicy;
    }

    /** @param list<array<string,mixed>> $rows @return list<MediaUsage> */
    private function hydrateList(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) { $usage = $this->hydrate($row); if ($usage !== null) $items[] = $usage; }
        return $items;
    }
}
