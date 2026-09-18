<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\{Media, MediaAsset, VisualSupportRequirement, VisualSupportRequirementStateRegistry};

final class VisualSupportPublicProjection
{
    /** @param list<MediaAsset> $assets @return array<string,mixed>|null */
    public function resolve(VisualSupportRequirement $requirement, ?Media $media, array $assets): ?array
    {
        if ($requirement->state !== VisualSupportRequirementStateRegistry::RESOLVED || $media === null || $requirement->mediaId !== $media->canonicalId || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return null;
        $asset = (new PublicMediaAssetSelector())->canonical(array_values(array_filter($assets, static fn (mixed $candidate): bool => $candidate instanceof MediaAsset && $candidate->mediaId === $media->canonicalId)));
        if ($asset === null) return null;
        $filename = is_string($asset->metadata['canonical_filename'] ?? null) ? $asset->metadata['canonical_filename'] : basename(str_replace('\\', '/', $asset->storageKey));
        return ['media_id' => $media->canonicalId, 'asset_id' => $asset->assetId, 'url' => (new PublicMediaAssetUrlResolver())->path($filename), 'visual_intent' => $requirement->visualIntent, 'feature_key' => $requirement->featureKey];
    }
}
