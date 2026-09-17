<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\{Media, MediaAsset};

/**
 * Read-only eligibility for Media consumers that need a public image before
 * contextual placement. It deliberately has no Usage/representative input:
 * those are downstream projections, not proof that the asset is deliverable.
 */
final class MediaPreBindingReadiness
{
    /** @param list<MediaAsset> $assets @return array{ready:bool,reason:?string,asset_id:?string} */
    public function check(Media $media, array $assets): array
    {
        if (!$media->active || $media->readiness !== 'ready') return ['ready' => false, 'reason' => 'MEDIA_NOT_READY', 'asset_id' => null];

        $derivatives = array_values(array_filter($assets, static fn (mixed $asset): bool => $asset instanceof MediaAsset && $asset->mediaId === $media->canonicalId && $asset->kind === 'derivative'));
        $asset = (new PublicMediaAssetSelector())->canonical($derivatives);
        if (!$asset instanceof MediaAsset) return ['ready' => false, 'reason' => 'MEDIA_PUBLIC_DERIVATIVE_REQUIRED', 'asset_id' => null];

        return ['ready' => true, 'reason' => null, 'asset_id' => $asset->assetId];
    }
}
