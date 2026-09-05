<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Media\PublicMediaAssetUrlResolver;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage, MediaUsageRoleRegistry};

/** Read-only endpoint image projection; it never promotes usage into semantic truth. */
final class EntityMediaProjection
{
    public function __construct(private MediaRepository $media, private MediaAssetRepository $assets, private MediaUsageRepository $usages) {}

    /** @return array{representative:?array<string,mixed>,evidence:list<array<string,mixed>>,gallery:list<array<string,mixed>>} */
    public function forEntity(string $endpointType, string $endpointKey): array
    {
        $representative = [];
        $evidence = [];
        $gallery = [];
        foreach ($this->usages->listByEndpoint($endpointType, $endpointKey) as $usage) {
            $item = $this->item($usage);
            if ($item === null) continue;
            $gallery[] = $item;
            if ($usage->role === MediaUsageRoleRegistry::REPRESENTATIVE || $usage->role === MediaUsageRoleRegistry::FEATURED_PRIMARY || $usage->role === 'featured') $representative[] = $item;
            if (in_array($usage->role, [MediaUsageRoleRegistry::EVIDENCE, MediaUsageRoleRegistry::TECHNICAL_DETAIL], true)) $evidence[] = $item;
        }
        $sort = static fn(array $left, array $right): int => [$left['sort_order'], $left['stable_key']] <=> [$right['sort_order'], $right['stable_key']];
        usort($representative, $sort); usort($evidence, $sort); usort($gallery, $sort);
        return ['representative' => $representative[0] ?? ($gallery[0] ?? null), 'evidence' => $evidence, 'gallery' => $gallery];
    }

    /** @return array<string,mixed>|null */
    private function item(MediaUsage $usage): ?array
    {
        $media = $this->media->findByCanonicalId($usage->mediaId);
        if (!$media instanceof Media || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return null;
        foreach ($this->assets->listByMediaId($media->canonicalId) as $asset) {
            if (!$asset instanceof MediaAsset || $asset->visibility !== 'PUBLIC' || !str_starts_with(strtolower($asset->mimeType), 'image/')) continue;
            $filename = is_string($asset->metadata['canonical_filename'] ?? null) && trim((string) $asset->metadata['canonical_filename']) !== '' ? (string) $asset->metadata['canonical_filename'] : basename(str_replace('\\', '/', $asset->storageKey));
            if ($filename === '') continue;
            $path = (new PublicMediaAssetUrlResolver())->path($filename);
            return ['media_id' => $media->canonicalId, 'asset_id' => $asset->assetId, 'stable_key' => $media->stableKey, 'url' => function_exists('home_url') ? (string) home_url($path) : $path, 'alt' => $usage->altText, 'role' => $usage->role, 'sort_order' => $usage->sortOrder];
        }
        return null;
    }
}
