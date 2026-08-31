<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

use NHK\Core\Domain\Media\MediaAsset;

interface MediaAssetRepository
{
    public function findByAssetId(string $id): ?MediaAsset;
    public function create(MediaAsset $asset): MediaAsset;
    /** @return list<MediaAsset> */
    public function listByMediaId(string $mediaId): array;
    /** @return list<MediaAsset> */
    public function findByChecksum(string $checksum): array;
}
