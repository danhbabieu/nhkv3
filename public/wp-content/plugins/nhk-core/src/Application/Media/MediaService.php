<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaException, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;

final class MediaService
{
    public function __construct(private MediaRepository $media, private MediaAssetRepository $assets, private MediaUsageRepository $usages)
    {
    }

    public function create(string $stableKey, string $name, string $readiness = 'draft', array $provenance = []): Media
    {
        $existing = $this->media->findByStableKey($stableKey);
        if ($existing) {
            if ($existing->canonicalName === $name && $existing->readiness === $readiness && $existing->provenance === $provenance) return $existing;
            throw new MediaException('Media stable key already exists.');
        }
        return $this->media->create(new Media(UuidCodec::newV7(), $stableKey, $name, $readiness, $provenance));
    }

    public function update(string $id, string $name, string $readiness, array $provenance, int $revision): Media
    {
        $current = $this->media->findByCanonicalId($id);
        if (!$current) throw new MediaException('Media not found.');
        return $this->media->update(new Media($current->canonicalId, $current->stableKey, $name, $readiness, $provenance, $current->active, $current->revision), $revision);
    }

    public function retire(string $id, int $revision): Media
    {
        return $this->changeState($id, $revision, false);
    }

    public function reactivate(string $id, int $revision): Media
    {
        return $this->changeState($id, $revision, true);
    }

    public function addAsset(string $mediaId, string $kind, string $storageKey, string $checksum, string $mimeType, int $byteSize, ?int $width = null, ?int $height = null): MediaAsset
    {
        if (!$this->media->findByCanonicalId($mediaId)) throw new MediaException('Media not found.');
        return $this->assets->create(new MediaAsset(UuidCodec::newV7(), $mediaId, $kind, $storageKey, $checksum, $mimeType, $byteSize, $width, $height));
    }

    public function addUsage(string $mediaId, string $endpointType, string $endpointKey, string $role, int $sortOrder = 0): MediaUsage
    {
        if (!$this->media->findByCanonicalId($mediaId)) throw new MediaException('Media not found.');
        return $this->usages->create(new MediaUsage(UuidCodec::newV7(), $mediaId, $endpointType, $endpointKey, $role, $sortOrder));
    }

    /** @return list<MediaAsset> */
    public function assets(string $mediaId): array { return $this->assets->listByMediaId($mediaId); }
    /** @return list<MediaUsage> */
    public function usages(string $mediaId, ?string $role = null): array { return $this->usages->listByMediaId($mediaId, $role); }

    private function changeState(string $id, int $revision, bool $active): Media
    {
        $current = $this->media->findByCanonicalId($id);
        if (!$current) throw new MediaException('Media not found.');
        if ($current->active === $active) return $current;
        return $this->media->update(new Media($current->canonicalId, $current->stableKey, $current->canonicalName, $current->readiness, $current->provenance, $active, $current->revision), $revision);
    }
}
