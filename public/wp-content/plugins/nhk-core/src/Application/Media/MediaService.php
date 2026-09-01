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

    /**
     * Ingest a complete Media semantic packet under the caller's transaction.
     * Binary delivery remains a separate, fail-closed concern; assets default
     * to PRIVATE until an explicit publication policy makes them public.
     *
     * @param list<array<string,mixed>> $assetSpecs
     * @param list<array<string,mixed>> $usageSpecs
     */
    public function ingest(string $stableKey, string $name, string $readiness = 'draft', array $provenance = [], array $assetSpecs = [], array $usageSpecs = []): Media
    {
        $media = $this->create($stableKey, $name, $readiness, $provenance);
        $existingAssets = $this->assets->listByMediaId($media->canonicalId);
        foreach ($assetSpecs as $spec) {
            $candidate = new MediaAsset(
                UuidCodec::newV7(),
                $media->canonicalId,
                (string) ($spec['kind'] ?? 'original'),
                (string) ($spec['storage_key'] ?? ''),
                (string) ($spec['checksum'] ?? ''),
                (string) ($spec['mime_type'] ?? ''),
                (int) ($spec['byte_size'] ?? 0),
                isset($spec['width']) ? (int) $spec['width'] : null,
                isset($spec['height']) ? (int) $spec['height'] : null,
                strtoupper((string) ($spec['visibility'] ?? 'PRIVATE')),
                is_array($spec['metadata'] ?? null) ? $spec['metadata'] : [],
            );
            $existing = null;
            foreach ($existingAssets as $asset) if ($asset->storageKey === $candidate->storageKey) { $existing = $asset; break; }
            if ($existing !== null) {
                if (!$this->sameAsset($existing, $candidate)) throw new MediaException('Media asset storage key is already bound to different content.');
                continue;
            }
            $existingAssets[] = $this->assets->create($candidate);
        }
        $existingUsages = $this->usages->listByMediaId($media->canonicalId);
        foreach ($usageSpecs as $spec) {
            $candidate = new MediaUsage(
                UuidCodec::newV7(),
                $media->canonicalId,
                (string) ($spec['endpoint_type'] ?? ''),
                (string) ($spec['endpoint_key'] ?? ''),
                (string) ($spec['role'] ?? ''),
                (int) ($spec['sort_order'] ?? 0),
            );
            $existing = null;
            foreach ($existingUsages as $usage) if ($usage->endpointType === $candidate->endpointType && $usage->endpointKey === $candidate->endpointKey && $usage->role === $candidate->role) { $existing = $usage; break; }
            if ($existing !== null) {
                if ($existing->sortOrder !== $candidate->sortOrder) throw new MediaException('Media usage is already bound to a different sort order.');
                continue;
            }
            $existingUsages[] = $this->usages->create($candidate);
        }
        return $media;
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

    public function addAsset(string $mediaId, string $kind, string $storageKey, string $checksum, string $mimeType, int $byteSize, ?int $width = null, ?int $height = null, string $visibility = 'PRIVATE', array $metadata = []): MediaAsset
    {
        if (!$this->media->findByCanonicalId($mediaId)) throw new MediaException('Media not found.');
        $candidate = new MediaAsset(UuidCodec::newV7(), $mediaId, $kind, $storageKey, $checksum, $mimeType, $byteSize, $width, $height, strtoupper($visibility), $metadata);
        foreach ($this->assets->listByMediaId($mediaId) as $existing) {
            if ($existing->storageKey !== $candidate->storageKey) continue;
            if ($this->sameAsset($existing, $candidate)) return $existing;
            throw new MediaException('Media asset storage key is already bound to different content.');
        }
        return $this->assets->create($candidate);
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

    private function sameAsset(MediaAsset $left, MediaAsset $right): bool
    {
        return $left->kind === $right->kind
            && $left->checksum === $right->checksum
            && $left->mimeType === $right->mimeType
            && $left->byteSize === $right->byteSize
            && $left->width === $right->width
            && $left->height === $right->height
            && $left->visibility === $right->visibility
            && $left->metadata === $right->metadata;
    }

    private function changeState(string $id, int $revision, bool $active): Media
    {
        $current = $this->media->findByCanonicalId($id);
        if (!$current) throw new MediaException('Media not found.');
        if ($current->active === $active) return $current;
        return $this->media->update(new Media($current->canonicalId, $current->stableKey, $current->canonicalName, $current->readiness, $current->provenance, $active, $current->revision), $revision);
    }
}
