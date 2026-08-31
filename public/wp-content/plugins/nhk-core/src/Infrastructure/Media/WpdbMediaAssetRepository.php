<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\MediaAssetRepository;
use NHK\Core\Domain\Media\{MediaAsset, MediaException};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbMediaAssetRepository implements MediaAssetRepository
{
    private string $table;
    private string $mediaTable;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_media_assets';
        $this->mediaTable = $database->prefix . 'nhk_media';
    }

    public function findByAssetId(string $id): ?MediaAsset
    {
        return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE asset_uuid=%s LIMIT 1", UuidCodec::toBinary($id)), ARRAY_A));
    }

    public function create(MediaAsset $asset): MediaAsset
    {
        $widthSql = $asset->width === null ? 'NULL' : '%d';
        $heightSql = $asset->height === null ? 'NULL' : '%d';
        $mediaId = $this->mediaInternalId($asset->mediaId);
        if ($mediaId === null) throw new MediaException('Media parent not found.');
        $args = [UuidCodec::toBinary($asset->assetId), $mediaId, $asset->kind, $asset->storageKey, $asset->checksum, $asset->mimeType, $asset->byteSize];
        foreach ([$asset->width, $asset->height] as $dimension) if ($dimension !== null) $args[] = $dimension;
        array_push($args, $asset->visibility, wp_json_encode($asset->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), gmdate('Y-m-d H:i:s.u'));
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (asset_uuid,media_id,asset_kind,storage_key,checksum,mime_type,byte_size,width,height,visibility,metadata_json,created_at) VALUES (%s,%s,%s,%s,UNHEX(%s),%s,%d,{$widthSql},{$heightSql},%s,%s,%s)", ...$args));
        if ($ok === false) {
            $existing = $this->findByAssetId($asset->assetId);
            if ($existing && $existing->mediaId === $asset->mediaId && $existing->storageKey === $asset->storageKey && $existing->checksum === $asset->checksum) return $existing;
            throw new MediaException('Media asset identity or storage key already exists.');
        }
        return $this->findByAssetId($asset->assetId) ?? $asset;
    }

    public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset
    {
        $widthSql = $asset->width === null ? 'NULL' : '%d';
        $heightSql = $asset->height === null ? 'NULL' : '%d';
        $args = [$asset->storageKey, $asset->mimeType, $asset->byteSize];
        foreach ([$asset->width, $asset->height] as $dimension) if ($dimension !== null) $args[] = $dimension;
        array_push($args, $asset->visibility, wp_json_encode($asset->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), UuidCodec::toBinary($asset->assetId));
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->table} SET storage_key=%s,mime_type=%s,byte_size=%d,width={$widthSql},height={$heightSql},visibility=%s,metadata_json=%s WHERE asset_uuid=%s", ...$args));
        if ($ok !== 1) throw new MediaException('Media asset update conflict.');
        return $this->findByAssetId($asset->assetId) ?? $asset;
    }

    public function listByMediaId(string $mediaId): array
    {
        $internalId = $this->mediaInternalId($mediaId);
        if ($internalId === null) return [];
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE media_id=%d ORDER BY id", $internalId), ARRAY_A);
        return $this->hydrateList($rows ?: []);
    }

    public function findByChecksum(string $checksum): array
    {
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE checksum=UNHEX(%s) ORDER BY id", $checksum), ARRAY_A);
        return $this->hydrateList($rows ?: []);
    }

    private function hydrate(?array $row): ?MediaAsset
    {
        if (!$row) return null;
        $mediaUuid = $this->database->get_var($this->database->prepare("SELECT canonical_uuid FROM {$this->mediaTable} WHERE id=%d LIMIT 1", (int) $row['media_id']));
        if (!is_string($mediaUuid) || strlen($mediaUuid) !== 16) return null;
        $metadata = (string) ($row['metadata_json'] ?? '');
        return new MediaAsset(UuidCodec::fromBinary($row['asset_uuid']), UuidCodec::fromBinary($mediaUuid), (string) $row['asset_kind'], (string) $row['storage_key'], bin2hex($row['checksum']), (string) $row['mime_type'], (int) $row['byte_size'], $row['width'] === null ? null : (int) $row['width'], $row['height'] === null ? null : (int) $row['height'], strtoupper((string) ($row['visibility'] ?? 'PUBLIC')), $metadata === '' ? [] : json_decode($metadata, true, 512, JSON_THROW_ON_ERROR));
    }

    private function mediaInternalId(string $mediaUuid): ?int { $id = $this->database->get_var($this->database->prepare("SELECT id FROM {$this->mediaTable} WHERE canonical_uuid=%s LIMIT 1", UuidCodec::toBinary($mediaUuid))); return $id === null ? null : (int) $id; }

    /** @param list<array<string,mixed>> $rows @return list<MediaAsset> */
    private function hydrateList(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $asset = $this->hydrate($row);
            if ($asset !== null) $items[] = $asset;
        }
        return $items;
    }
}
