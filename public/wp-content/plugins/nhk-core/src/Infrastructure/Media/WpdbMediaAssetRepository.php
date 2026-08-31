<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\MediaAssetRepository;
use NHK\Core\Domain\Media\{MediaAsset, MediaException};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbMediaAssetRepository implements MediaAssetRepository
{
    private string $table;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_media_assets';
    }

    public function findByAssetId(string $id): ?MediaAsset
    {
        return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE asset_uuid=%s LIMIT 1", UuidCodec::toBinary($id)), ARRAY_A));
    }

    public function create(MediaAsset $asset): MediaAsset
    {
        $widthSql = $asset->width === null ? 'NULL' : '%d';
        $heightSql = $asset->height === null ? 'NULL' : '%d';
        $args = [UuidCodec::toBinary($asset->assetId), UuidCodec::toBinary($asset->mediaId), $asset->kind, $asset->storageKey, $asset->checksum, $asset->mimeType, $asset->byteSize];
        foreach ([$asset->width, $asset->height] as $dimension) if ($dimension !== null) $args[] = $dimension;
        $args[] = gmdate('Y-m-d H:i:s.u');
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (asset_uuid,media_id,asset_kind,storage_key,checksum,mime_type,byte_size,width,height,created_at) VALUES (%s,%s,%s,%s,UNHEX(%s),%s,%d,{$widthSql},{$heightSql},%s)", ...$args));
        if ($ok === false) {
            $existing = $this->findByAssetId($asset->assetId);
            if ($existing && $existing->mediaId === $asset->mediaId && $existing->storageKey === $asset->storageKey && $existing->checksum === $asset->checksum) return $existing;
            throw new MediaException('Media asset identity or storage key already exists.');
        }
        return $this->findByAssetId($asset->assetId) ?? $asset;
    }

    public function listByMediaId(string $mediaId): array
    {
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE media_id=%s ORDER BY id", UuidCodec::toBinary($mediaId)), ARRAY_A);
        return array_map(fn (array $row): MediaAsset => $this->hydrate($row), $rows ?: []);
    }

    public function findByChecksum(string $checksum): array
    {
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE checksum=UNHEX(%s) ORDER BY id", $checksum), ARRAY_A);
        return array_map(fn (array $row): MediaAsset => $this->hydrate($row), $rows ?: []);
    }

    private function hydrate(?array $row): ?MediaAsset
    {
        if (!$row) return null;
        return new MediaAsset(UuidCodec::fromBinary($row['asset_uuid']), UuidCodec::fromBinary($row['media_id']), (string) $row['asset_kind'], (string) $row['storage_key'], bin2hex($row['checksum']), (string) $row['mime_type'], (int) $row['byte_size'], $row['width'] === null ? null : (int) $row['width'], $row['height'] === null ? null : (int) $row['height']);
    }
}
