<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\MediaAsset;

/** Selects the public canonical image without treating a thumbnail as full-size. */
final class PublicMediaAssetSelector
{
    public const DEFAULT_WEBP_QUALITY = 86;

    /** @param list<MediaAsset> $assets */
    public function canonical(array $assets): ?MediaAsset
    {
        $candidates = array_values(array_filter($assets, function (mixed $asset): bool {
            return $asset instanceof MediaAsset
                && $asset->visibility === 'PUBLIC'
                && str_starts_with(strtolower($asset->mimeType), 'image/')
                && ($asset->width ?? 0) > 0
                && ($asset->height ?? 0) > 0
                && max($asset->width ?? 0, $asset->height ?? 0) <= PublicImageSizingPolicy::MAX_LONG_EDGE
                && !$this->isThumbnail($asset);
        }));

        usort($candidates, static function (MediaAsset $left, MediaAsset $right): int {
            $leftArea = ($left->width ?? 0) * ($left->height ?? 0);
            $rightArea = ($right->width ?? 0) * ($right->height ?? 0);
            return [$rightArea, $right->width ?? 0, $right->height ?? 0, $left->assetId]
                <=> [$leftArea, $left->width ?? 0, $left->height ?? 0, $right->assetId];
        });

        $selected = $candidates[0] ?? null;
        if ($selected === null) return null;

        return $selected;
    }

    private function isThumbnail(MediaAsset $asset): bool
    {
        $metadata = $asset->metadata;
        if (($metadata['thumbnail'] ?? false) === true || ($metadata['is_thumbnail'] ?? false) === true) return true;
        $role = strtolower(trim((string) ($metadata['role'] ?? $metadata['size'] ?? '')));
        return in_array($role, ['thumbnail', 'thumb', 'small'], true);
    }
}
