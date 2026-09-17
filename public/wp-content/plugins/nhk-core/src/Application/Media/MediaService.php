<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository, MediaUsageUpdater};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaException, MediaUsage};
use NHK\Core\Domain\Media\{MediaDetailTypeRegistry, SeoKeywordGroupRegistry};
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
        // Ingest is create-or-resolve. A stable key may be replayed with a
        // usage/asset delta; that delta must not be mistaken for a duplicate
        // Media create. Explicit Media updates remain on update().
        $media = $this->media->findByStableKey($stableKey) ?? $this->create($stableKey, $name, $readiness, $provenance);
        $existingAssets = $this->assets->listByMediaId($media->canonicalId);
        foreach ($assetSpecs as $spec) {
            $spec = $this->normalizeAssetSpec($spec, $name, $provenance);
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
                if ($this->sameAsset($existing, $candidate)) continue;
                if (!$this->samePhysicalAsset($existing, $candidate)) throw new MediaException('Media asset storage key is already bound to different content.');
                $updated = $this->assets->update(new MediaAsset($existing->assetId, $existing->mediaId, $existing->kind, $existing->storageKey, $existing->checksum, $existing->mimeType, $existing->byteSize, $existing->width, $existing->height, $candidate->visibility, array_replace($existing->metadata, $candidate->metadata)));
                foreach ($existingAssets as $index => $current) if ($current->assetId === $existing->assetId) { $existingAssets[$index] = $updated; break; }
                continue;
            }
            $existingAssets[] = $this->assets->create($candidate);
        }
        foreach ($usageSpecs as $spec) {
            $candidate = new MediaUsage(
                UuidCodec::newV7(),
                $media->canonicalId,
                (string) ($spec['endpoint_type'] ?? ''),
                (string) ($spec['endpoint_key'] ?? ''),
                (string) ($spec['role'] ?? ''),
                (int) ($spec['sort_order'] ?? 0),
                (string) ($spec['alt_text'] ?? ''),
                (string) ($spec['caption'] ?? ''),
                is_array($spec['keyword_groups'] ?? null) ? array_values(array_map('strval', $spec['keyword_groups'])) : [],
                (string) ($spec['title'] ?? ''),
                (int) ($spec['revision'] ?? 1),
                (string) ($spec['placement_key'] ?? ''),
            );
            $this->reconcileUsageCandidate($candidate);
        }
        // Canonical read-back is the only handoff to reverse semantic visual
        // reconciliation. Capture remains the intake boundary; this event is
        // deliberately not an MCP writer or a second Media identity path.
        $this->emitCanonicalReadback($media);
        return $media;
    }

    public function update(string $id, string $name, string $readiness, array $provenance, int $revision): Media
    {
        $current = $this->media->findByCanonicalId($id);
        if (!$current) throw new MediaException('Media not found.');
        $updated = $this->media->update(new Media($current->canonicalId, $current->stableKey, $name, $readiness, $provenance, $current->active, $current->revision), $revision);
        $this->emitCanonicalReadback($updated);
        return $updated;
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
        $parent = $this->media->findByCanonicalId($mediaId);
        if (!$parent) throw new MediaException('Media not found.');
        $spec = $this->normalizeAssetSpec(['kind' => $kind, 'storage_key' => $storageKey, 'checksum' => $checksum, 'mime_type' => $mimeType, 'byte_size' => $byteSize, 'width' => $width, 'height' => $height, 'visibility' => $visibility, 'metadata' => $metadata], $parent->canonicalName, $parent->provenance);
        $kind = (string) $spec['kind']; $storageKey = (string) $spec['storage_key']; $checksum = (string) $spec['checksum']; $mimeType = (string) $spec['mime_type']; $byteSize = (int) $spec['byte_size']; $width = isset($spec['width']) ? (int) $spec['width'] : null; $height = isset($spec['height']) ? (int) $spec['height'] : null; $visibility = (string) $spec['visibility']; $metadata = is_array($spec['metadata'] ?? null) ? $spec['metadata'] : [];
        $candidate = new MediaAsset(UuidCodec::newV7(), $mediaId, $kind, $storageKey, $checksum, $mimeType, $byteSize, $width, $height, strtoupper($visibility), $metadata);
        foreach ($this->assets->listByMediaId($mediaId) as $existing) {
            if ($existing->storageKey !== $candidate->storageKey) continue;
            if ($this->sameAsset($existing, $candidate)) return $existing;
            if ($this->samePhysicalAsset($existing, $candidate)) {
                return $this->assets->update(new MediaAsset($existing->assetId, $existing->mediaId, $existing->kind, $existing->storageKey, $existing->checksum, $existing->mimeType, $existing->byteSize, $existing->width, $existing->height, $candidate->visibility, array_replace($existing->metadata, $candidate->metadata)));
            }
            throw new MediaException('Media asset storage key is already bound to different content.');
        }
        $created = $this->assets->create($candidate);
        $this->emitCanonicalReadback($parent);
        return $created;
    }

    /**
     * Complete a previously staged binary ingest after all read-back checks
     * have passed. A failed readiness transition leaves the asset private.
     */
    public function completeIngest(string $mediaId, string $assetId, array $metadata = []): Media
    {
        $media = $this->media->findByCanonicalId($mediaId);
        $asset = $this->assets->findByAssetId($assetId);
        if (!$media || !$asset || $asset->mediaId !== $mediaId) throw new MediaException('Media ingest completion target is invalid.');
        if ($media->readiness === 'ready' && $asset->visibility === 'PUBLIC') return $media;

        $completedMetadata = array_replace($asset->metadata, $metadata);
        $assetWasUpdated = $asset->visibility !== 'PUBLIC' || $asset->metadata !== $completedMetadata;
        if ($assetWasUpdated) {
            $public = new MediaAsset($asset->assetId, $asset->mediaId, $asset->kind, $asset->storageKey, $asset->checksum, $asset->mimeType, $asset->byteSize, $asset->width, $asset->height, 'PUBLIC', $completedMetadata);
            $this->assets->update($public);
        }
        try {
            return $this->update($mediaId, $media->canonicalName, 'ready', $media->provenance, $media->revision);
        } catch (\Throwable $error) {
            if ($assetWasUpdated) {
                try { $this->assets->update($asset); } catch (\Throwable) { }
            }
            throw $error;
        }
    }

    public function addUsage(string $mediaId, string $endpointType, string $endpointKey, string $role, int $sortOrder = 0, string $altText = '', string $caption = '', array $keywordGroups = [], string $title = '', string $placementKey = ''): MediaUsage
    {
        if (!$this->media->findByCanonicalId($mediaId)) throw new MediaException('Media not found.');
        $candidate = new MediaUsage(UuidCodec::newV7(), $mediaId, $endpointType, $endpointKey, $role, $sortOrder, $altText, $caption, $keywordGroups, $title, 1, $placementKey);
        return $this->reconcileUsageCandidate($candidate);
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

    private function samePhysicalAsset(MediaAsset $left, MediaAsset $right): bool
    {
        return $left->mediaId === $right->mediaId
            && $left->kind === $right->kind
            && $left->storageKey === $right->storageKey
            && $left->checksum === $right->checksum
            && $left->mimeType === $right->mimeType
            && $left->byteSize === $right->byteSize
            && $left->width === $right->width
            && $left->height === $right->height;
    }

    private function sameUsage(MediaUsage $left, MediaUsage $right): bool
    {
        return $left->mediaId === $right->mediaId
            && $left->endpointType === $right->endpointType
            && $left->endpointKey === $right->endpointKey
            && $left->role === $right->role
            && $left->sortOrder === $right->sortOrder
            && $left->altText === $right->altText
            && $left->caption === $right->caption
            && $left->keywordGroups === $right->keywordGroups
            && $left->title === $right->title
            && $left->placementKey === $right->placementKey;
    }

    private function upsertUsage(MediaUsage $existing, MediaUsage $candidate): MediaUsage
    {
        if (!$this->usages instanceof MediaUsageUpdater) throw new MediaException('Media usage update capability is unavailable.');
        $attempts = 0;
        while (true) {
            $updated = new MediaUsage($existing->usageId, $candidate->mediaId, $candidate->endpointType, $candidate->endpointKey, $candidate->role, $candidate->sortOrder, $candidate->altText, $candidate->caption, $candidate->keywordGroups, $candidate->title, $existing->revision, $candidate->placementKey);
            try {
                return $this->usages->update($updated);
            } catch (MediaException $error) {
                if (strtolower(trim($error->getMessage())) !== 'media usage update conflict.' || $attempts >= 1) throw $error;
                ++$attempts;
                $refreshed = null;
                foreach ($this->usages->listByEndpoint($candidate->endpointType, $candidate->endpointKey, $candidate->role) as $current) {
                    if ($current->endpointType === $candidate->endpointType && $current->endpointKey === $candidate->endpointKey && $current->role === $candidate->role && $current->placementKey === $candidate->placementKey) {
                        $refreshed = $current;
                        break;
                    }
                }
                if (!$refreshed instanceof MediaUsage) throw $error;
                if ($this->sameUsage($refreshed, $candidate)) return $refreshed;
                $existing = $refreshed;
            }
        }
    }

    private function reconcileUsageCandidate(MediaUsage $candidate): MediaUsage
    {
        $matches = array_values(array_filter(
            $this->usages->listByEndpoint($candidate->endpointType, $candidate->endpointKey, $candidate->role),
            static fn (mixed $usage): bool => $usage instanceof MediaUsage && $usage->placementKey === $candidate->placementKey,
        ));
        if (count($matches) > 1) throw new MediaException('MEDIA_USAGE_RECONCILE_CONFLICT');
        $existing = $matches[0] ?? null;
        if ($existing instanceof MediaUsage) {
            if ($this->sameUsage($existing, $candidate)) return $existing;
            return $this->upsertUsage($existing, $candidate);
        }

        try {
            return $this->usages->create($candidate);
        } catch (MediaException $error) {
            // A repository may observe a concurrent create after the lookup.
            // Resolve the canonical endpoint identity and then apply the same
            // deterministic KEEP/UPDATE decision; this is not a blanket
            // duplicate-error suppression path.
            $raced = array_values(array_filter(
                $this->usages->listByEndpoint($candidate->endpointType, $candidate->endpointKey, $candidate->role),
                static fn (mixed $usage): bool => $usage instanceof MediaUsage && $usage->placementKey === $candidate->placementKey,
            ));
            if (count($raced) === 1) {
                $existing = $raced[0];
                if ($this->sameUsage($existing, $candidate)) return $existing;
                return $this->upsertUsage($existing, $candidate);
            }
            throw $error;
        }
    }

    private function changeState(string $id, int $revision, bool $active): Media
    {
        $current = $this->media->findByCanonicalId($id);
        if (!$current) throw new MediaException('Media not found.');
        if ($current->active === $active) return $current;
        return $this->media->update(new Media($current->canonicalId, $current->stableKey, $current->canonicalName, $current->readiness, $current->provenance, $active, $current->revision), $revision);
    }

    private function emitCanonicalReadback(Media $media): void
    {
        if (!function_exists('do_action')) return;
        $assets = $this->assets->listByMediaId($media->canonicalId);
        $contexts = is_array($media->provenance['visual_support_contexts'] ?? null) ? $media->provenance['visual_support_contexts'] : [];
        foreach ($assets as $asset) {
            $context = $asset->metadata['visual_support_context'] ?? $asset->metadata['visual_context'] ?? null;
            if (is_array($context)) $contexts[] = $context;
        }
        do_action('nhk_v3_media_canonical_readback', $media, $assets, array_values($contexts));
    }

    /** @param array<string,mixed> $spec @param array<string,mixed> $provenance @return array<string,mixed> */
    private function normalizeAssetSpec(array $spec, string $subject, array $provenance): array
    {
        $metadata = is_array($spec['metadata'] ?? null) ? $spec['metadata'] : [];
        if (isset($metadata['detail_type'])) MediaDetailTypeRegistry::assertKnown((string) $metadata['detail_type']);
        if (isset($metadata['keyword_groups'])) {
            if (!is_array($metadata['keyword_groups'])) throw new MediaException('Media keyword groups must be a list.');
            foreach ($metadata['keyword_groups'] as $group) SeoKeywordGroupRegistry::assertKnown((string) $group);
        }
        // Storage keys identify already-persisted physical bytes. Filename
        // normalization belongs to the upload/derivative owner before this
        // semantic boundary; changing the key here breaks attachment
        // read-back when the original filename is a camera name.
        $storageKey = $spec['storage_key'] ?? null;
        if (!is_string($storageKey) || $storageKey === '' || strlen($storageKey) > 255 || preg_match('/[\x00-\x1F\x7F]/', $storageKey) === 1 || str_contains($storageKey, '\\') || str_starts_with($storageKey, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $storageKey) === 1 || preg_match('#(^|/)\.\.?(/|$)#', $storageKey) === 1) {
            throw new MediaException('Media asset storage key is invalid.');
        }
        $spec['storage_key'] = $storageKey;
        $spec['metadata'] = $metadata;
        return $spec;
    }
}
