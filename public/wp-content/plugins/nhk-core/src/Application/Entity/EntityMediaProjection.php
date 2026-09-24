<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Media\{PublicMediaAssetUrlResolver, SemanticSuitabilityPolicy};
use NHK\Core\Application\Media\PublicMediaAssetSelector;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage, MediaUsageRoleRegistry};

/** Read-only endpoint image projection; it never promotes usage into semantic truth. */
final class EntityMediaProjection
{
    public function __construct(private MediaRepository $media, private MediaAssetRepository $assets, private MediaUsageRepository $usages, private ?SemanticSuitabilityPolicy $suitabilityPolicy = null) {}

    /** @return array{representative:?array<string,mixed>,evidence:list<array<string,mixed>>,gallery:list<array<string,mixed>>} */
    public function forEntity(string $endpointType, string $endpointKey): array
    {
        $representative = [];
        $evidence = [];
        $gallery = [];
        foreach ($this->usages->listByEndpoint($endpointType, $endpointKey) as $usage) {
            if ($usage->activeSlot === 'retired') continue;
            $item = $this->item($usage);
            if ($item === null) continue;
            $gallery[] = $item;
            if (($usage->role === MediaUsageRoleRegistry::REPRESENTATIVE && ($usage->activeSlot === null || $usage->activeSlot === 'representative')) || ($endpointType === 'wp_post' && in_array($usage->role, [MediaUsageRoleRegistry::FEATURED_PRIMARY, 'featured'], true))) $representative[] = $item;
            if (in_array($usage->role, [MediaUsageRoleRegistry::EVIDENCE, MediaUsageRoleRegistry::TECHNICAL_DETAIL], true)) $evidence[] = $item;
        }
        $sort = static fn(array $left, array $right): int => [$left['sort_order'], $left['stable_key']] <=> [$right['sort_order'], $right['stable_key']];
        usort($representative, $sort); usort($evidence, $sort); usort($gallery, $sort);
        return ['representative' => $representative[0] ?? null, 'evidence' => $evidence, 'gallery' => $gallery];
    }

    /** @return array<string,mixed>|null */
    private function item(MediaUsage $usage): ?array
    {
        $media = $this->media->findByCanonicalId($usage->mediaId);
        if (!$media instanceof Media || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return null;
        // wp_post does not carry an Authority subject key at this generic
        // projection boundary; Article preflight/SEO supplies the canonical
        // subject-bound read model. Authority endpoints can validate directly
        // against their endpoint key here.
        if ($usage->endpointType !== 'wp_post') {
            $assessment = ($this->suitabilityPolicy ??= new SemanticSuitabilityPolicy())->evaluateMedia($media, $this->assets->listByMediaId($media->canonicalId), ['subject_ids' => [$usage->endpointKey]], 'SYSTEM_AUTO', $usage->role);
            if (($assessment['valid_for_completeness'] ?? false) !== true) return null;
        }
        $asset = (new PublicMediaAssetSelector())->canonical($this->assets->listByMediaId($media->canonicalId));
        if (!$asset instanceof MediaAsset) return null;
        $filename = is_string($asset->metadata['canonical_filename'] ?? null) && trim((string) $asset->metadata['canonical_filename']) !== '' ? (string) $asset->metadata['canonical_filename'] : basename(str_replace('\\', '/', $asset->storageKey));
        if ($filename === '') return null;
        $path = (new PublicMediaAssetUrlResolver())->path($filename);
        return ['media_id' => $media->canonicalId, 'asset_id' => $asset->assetId, 'stable_key' => $media->stableKey, 'url' => function_exists('home_url') ? (string) home_url($path) : $path, 'title' => $usage->title, 'alt' => $usage->altText, 'caption' => $usage->caption, 'width' => $asset->width, 'height' => $asset->height, 'role' => $usage->role, 'sort_order' => $usage->sortOrder];
    }
}
