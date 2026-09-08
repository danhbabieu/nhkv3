<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\MediaAsset;

/** Selects the public canonical image without treating a thumbnail as full-size. */
final class PublicMediaAssetSelector
{
    public const MIN_CANONICAL_WIDTH = 900;
    public const DEFAULT_WEBP_QUALITY = 86;

    /** @param list<MediaAsset> $assets */
    public function canonical(array $assets): ?MediaAsset
    {
        $source = $this->sourceOriginal($assets);
        $candidates = array_values(array_filter($assets, function (mixed $asset): bool {
            return $asset instanceof MediaAsset
                && $asset->visibility === 'PUBLIC'
                && str_starts_with(strtolower($asset->mimeType), 'image/')
                && ($asset->width ?? 0) > 0
                && ($asset->height ?? 0) > 0
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

        // If the source is large, publishing a small derivative would hide a
        // missing projection job. Fail closed until a source-derived asset is
        // generated; never upscale the small derivative here.
        if ($source !== null && ($source->width ?? 0) >= self::MIN_CANONICAL_WIDTH && ($selected->width ?? 0) < self::MIN_CANONICAL_WIDTH) return null;

        return $selected;
    }

    /** @param list<MediaAsset> $assets */
    private function sourceOriginal(array $assets): ?MediaAsset
    {
        foreach ($assets as $asset) if ($asset instanceof MediaAsset && $asset->kind === 'original') return $asset;
        return null;
    }

    private function isThumbnail(MediaAsset $asset): bool
    {
        $metadata = $asset->metadata;
        if (($metadata['thumbnail'] ?? false) === true || ($metadata['is_thumbnail'] ?? false) === true) return true;
        $role = strtolower(trim((string) ($metadata['role'] ?? $metadata['size'] ?? '')));
        return in_array($role, ['thumbnail', 'thumb', 'small'], true);
    }
}
