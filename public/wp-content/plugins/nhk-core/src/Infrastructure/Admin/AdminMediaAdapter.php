<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};

final class AdminMediaAdapter
{
    /** @param iterable<Media> $media */
    public function __construct(iterable $media, iterable $assets = [], iterable $usages = [])
    {
        $this->media = $this->values($media);
        $this->assets = $this->values($assets);
        $this->usages = $this->values($usages);
    }

    /** @var list<Media> */
    private array $media;
    /** @var list<MediaAsset> */
    private array $assets;
    /** @var list<MediaUsage> */
    private array $usages;

    /** @return list<mixed> */
    private function values(iterable $items): array { return array_values(is_array($items) ? $items : iterator_to_array($items, false)); }

    /** @return list<array<string,mixed>> */
    public function find(string $query = ''): array
    {
        $query = strtolower(trim($query)); $rows = [];
        foreach ($this->media as $item) {
            if (!$item instanceof Media) continue;
            $haystack = strtolower($item->canonicalName . ' ' . $item->stableKey . ' ' . $item->canonicalId);
            if ($query !== '' && !str_contains($haystack, $query)) continue;
            $assets = $this->assets($item->canonicalId);
            $usages = $this->usages($item->canonicalId);
            $rows[] = ['id' => $item->canonicalId, 'title' => $item->canonicalName, 'stable_key' => $item->stableKey, 'readiness' => $item->readiness, 'active' => $item->active, 'dimensions' => $this->dimensions($assets), 'semantic_role' => $usages[0]->role ?? null, 'primary_entity' => $this->primaryEntity($usages), 'usage_count' => count($usages), 'public_state' => $this->publicState($item, $assets), 'frontend_state' => 'entity_or_gallery_projection', 'completion' => $this->completion($item, $assets, $usages), 'evidence_state' => $this->evidenceState($usages), 'revision' => $item->revision, 'placeholder' => $item->isSystemPlaceholder()];
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function detail(Media $media): array
    {
        $assets = $this->assets($media->canonicalId);
        $usages = $this->usages($media->canonicalId);
        $publicAssets = array_values(array_filter($assets, static fn (MediaAsset $asset): bool => $asset->visibility === 'PUBLIC'));

        return [
            'media' => ['id' => $media->canonicalId, 'title' => $media->canonicalName, 'stable_key' => $media->stableKey, 'readiness' => $media->readiness, 'active' => $media->active, 'provenance' => $media->provenance, 'revision' => $media->revision, 'placeholder' => $media->isSystemPlaceholder()],
            'assets' => array_map(static fn (MediaAsset $asset): array => ['id' => $asset->assetId, 'kind' => $asset->kind, 'mime_type' => $asset->mimeType, 'byte_size' => $asset->byteSize, 'width' => $asset->width, 'height' => $asset->height, 'visibility' => $asset->visibility], $assets),
            'usages' => array_map(static fn (MediaUsage $usage): array => ['usage_id' => $usage->usageId, 'role' => $usage->role, 'endpoint_type' => $usage->endpointType, 'endpoint_key' => $usage->endpointKey, 'sort_order' => $usage->sortOrder, 'alt' => $usage->altText, 'caption' => $usage->caption], $usages),
            'semantic_role' => $usages[0]->role ?? null,
            'relation_count' => count(array_filter($usages, static fn (MediaUsage $usage): bool => $usage->endpointType !== 'wp_post')),
            'usage_count' => count($usages),
            'provenance' => $media->provenance,
            'frontend_state' => $media->active && $media->readiness === 'ready' && $publicAssets !== [] ? 'available' : 'missing',
            'completion' => $this->completion($media, $assets, $usages),
        ];
    }

    /** @return list<MediaAsset> */
    private function assets(string $mediaId): array { return array_values(array_filter($this->assets, static fn (mixed $asset): bool => $asset instanceof MediaAsset && $asset->mediaId === $mediaId)); }
    /** @return list<MediaUsage> */
    private function usages(string $mediaId): array { return array_values(array_filter($this->usages, static fn (mixed $usage): bool => $usage instanceof MediaUsage && $usage->mediaId === $mediaId)); }
    /** @param list<MediaAsset> $assets */
    private function dimensions(array $assets): ?string { foreach ($assets as $asset) if ($asset->visibility === 'PUBLIC' && $asset->width !== null && $asset->height !== null) return $asset->width . ' × ' . $asset->height; return null; }
    /** @param list<MediaUsage> $usages */
    private function primaryEntity(array $usages): ?array { foreach ($usages as $usage) if ($usage->role === 'representative') return ['type' => $usage->endpointType, 'key' => $usage->endpointKey]; return null; }
    /** @param list<MediaAsset> $assets */
    private function publicState(Media $media, array $assets): string { if (!$media->active || $media->readiness !== 'ready') return 'private_or_not_ready'; foreach ($assets as $asset) if ($asset->visibility === 'PUBLIC') return 'public'; return 'missing_public_asset'; }
    /** @param list<MediaUsage> $usages */
    private function evidenceState(array $usages): string { foreach ($usages as $usage) if (in_array($usage->role, ['evidence', 'technical_detail'], true)) return 'present'; return 'missing'; }

    /** @param list<MediaAsset> $assets @param list<MediaUsage> $usages @return array<string,mixed> */
    private function completion(Media $media, array $assets, array $usages): array
    {
        $privateOriginal = false; $publicDerivative = false;
        foreach ($assets as $asset) {
            $privateOriginal = $privateOriginal || ($asset->kind === 'original' && $asset->visibility === 'PRIVATE');
            $publicDerivative = $publicDerivative || ($asset->kind === 'derivative' && $asset->visibility === 'PUBLIC');
        }
        return (new CompletionCoordinator())->finalize('media', $media->canonicalId, [
            'canonical_readback' => ['canonical_id' => $media->canonicalId],
            'dependency_state' => $privateOriginal ? 'COMPLETE' : 'PARTIAL',
            'relation_or_usage_state' => $usages === [] ? 'NOT_APPLICABLE' : 'COMPLETE',
            'public_eligible' => $media->active && $media->readiness === 'ready' && $publicDerivative,
            'frontend_state' => 'BLOCKED',
            'blockers' => $media->active && $media->readiness === 'ready' && $publicDerivative ? ['MEDIA_FRONTEND_READBACK_REQUIRED'] : ['MEDIA_PUBLIC_DERIVATIVE_REQUIRED'],
        ]);
    }
}
