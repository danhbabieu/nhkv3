<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Domain\Media\{MediaException, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbMediaUsageRepository implements MediaUsageRepository
{
    private string $table;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_media_usages';
    }

    public function create(MediaUsage $usage): MediaUsage
    {
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (usage_uuid,media_id,endpoint_type,endpoint_key,usage_role,sort_order,created_at) VALUES (%s,%s,%s,%s,%s,%d,%s)", UuidCodec::toBinary($usage->usageId), UuidCodec::toBinary($usage->mediaId), $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder, gmdate('Y-m-d H:i:s.u')));
        if ($ok === false) {
            $existing = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE media_id=%s AND endpoint_type=%s AND endpoint_key=%s AND usage_role=%s LIMIT 1", UuidCodec::toBinary($usage->mediaId), $usage->endpointType, $usage->endpointKey, $usage->role), ARRAY_A);
            if (is_array($existing)) return $this->hydrate($existing);
            throw new MediaException('Media usage identity already exists.');
        }
        $row = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE usage_uuid=%s LIMIT 1", UuidCodec::toBinary($usage->usageId)), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : $usage;
    }

    public function listByMediaId(string $mediaId, ?string $role = null): array
    {
        $where = 'media_id=%s';
        $args = [UuidCodec::toBinary($mediaId)];
        if ($role !== null) { $where .= ' AND usage_role=%s'; $args[] = $role; }
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE {$where} ORDER BY sort_order,id", ...$args), ARRAY_A);
        return array_map(fn (array $row): MediaUsage => $this->hydrate($row), $rows ?: []);
    }

    public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array
    {
        $where = 'endpoint_type=%s AND endpoint_key=%s';
        $args = [$endpointType, $endpointKey];
        if ($role !== null) { $where .= ' AND usage_role=%s'; $args[] = $role; }
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE {$where} ORDER BY sort_order,id", ...$args), ARRAY_A);
        return array_map(fn (array $row): MediaUsage => $this->hydrate($row), $rows ?: []);
    }

    private function hydrate(array $row): MediaUsage
    {
        return new MediaUsage(UuidCodec::fromBinary($row['usage_uuid']), UuidCodec::fromBinary($row['media_id']), (string) $row['endpoint_type'], (string) $row['endpoint_key'], (string) $row['usage_role'], (int) $row['sort_order']);
    }
}
