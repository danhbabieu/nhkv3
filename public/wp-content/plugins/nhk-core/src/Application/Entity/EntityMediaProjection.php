<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Application\Media\PublicMediaAssetUrlResolver;

/** Read-only entity image projection; it never promotes usage into semantic truth. */
final class EntityMediaProjection
{
    public function __construct(private MediaRepository $media, private MediaAssetRepository $assets, private MediaUsageRepository $usages) {}

    /** @return array{representative:?array<string,mixed>,evidence:list<array<string,mixed>>} */
    public function forEntity(string $endpointType, string $endpointKey): array
    {
        $representative = [];
        $evidence = [];
        foreach ($this->usages->listByEndpoint($endpointType, $endpointKey) as $usage) {
            $item = $this->item($usage);
            if ($item === null) continue;
            if ($usage->role === MediaUsageRoleRegistry::REPRESENTATIVE) $representative[] = $item;
            elseif (in_array($usage->role, [MediaUsageRoleRegistry::EVIDENCE, MediaUsageRoleRegistry::TECHNICAL_DETAIL], true)) $evidence[] = $item;
        }
        usort($representative, static fn (array $left, array $right): int => [$left['sort_order'], $left['stable_key']] <=> [$right['sort_order'], $right['stable_key']]);
        usort($evidence, static fn (array $left, array $right): int => [$left['sort_order'], $left['stable_key']] <=> [$right['sort_order'], $right['stable_key']]);
        return ['representative' => $representative[0] ?? null, 'evidence' => $evidence];
    }

    /** @return array<string,mixed>|null */
    private function item(MediaUsage $usage): ?array
    {
        $media = $this->media->findByCanonicalId($usage->mediaId);
        if (!$media instanceof Media || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return null;
        foreach ($this->assets->listByMediaId($media->canonicalId) as $asset) {
            if (!$asset instanceof MediaAsset || $asset->visibility !== 'PUBLIC') continue;
            $filename = is_string($asset->metadata['canonical_filename'] ?? null) ? (string) $asset->metadata['canonical_filename'] : basename($asset->storageKey);
            $path = (new PublicMediaAssetUrlResolver())->path($filename);
            return ['media_id' => $media->canonicalId, 'asset_id' => $asset->assetId, 'stable_key' => $media->stableKey, 'url' => function_exists('home_url') ? (string) home_url($path) : $path, 'alt' => $usage->altText, 'sort_order' => $usage->sortOrder];
        }
        return null;
    }
}
