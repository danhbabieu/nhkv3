<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository, WordPressArticleMediaAdapter};
use NHK\Core\Domain\Media\{MediaSeoStateRegistry, MediaUsageRoleRegistry};

final class ArticleMediaSeoProjection
{
    public function __construct(private MediaRepository $media, private MediaAssetRepository $assets, private MediaUsageRepository $usages, private ?WordPressArticleMediaAdapter $wordpress = null) {}

    /** @return array<string,mixed> */
    public function forPost(string $endpointKey): array
    {
        $usages = $this->usages->listByEndpoint('wp_post', $endpointKey, MediaUsageRoleRegistry::FEATURED_PRIMARY);
        if (count($usages) !== 1) return ['state' => MediaSeoStateRegistry::INCOMPLETE_FEATURED, 'eligible' => false, 'image_url' => null];
        $media = $this->media->findByCanonicalId($usages[0]->mediaId);
        if ($media === null || !$media->active || $media->isSystemPlaceholder()) return ['state' => MediaSeoStateRegistry::PLACEHOLDER, 'eligible' => false, 'image_url' => null];
        $assets = array_values(array_filter($this->assets->listByMediaId($media->canonicalId), static fn ($asset): bool => $asset->visibility === 'PUBLIC'));
        if ($assets === []) return ['state' => MediaSeoStateRegistry::METADATA_INCOMPLETE, 'eligible' => false, 'image_url' => null];
        $asset = $assets[0];
        $representation = [];
        if ($this->wordpress !== null) {
            try { $representation = $this->wordpress->attachmentForMedia($media, $asset, (string) ($usages[0]->altText ?? '')); } catch (\Throwable) { $representation = []; }
        }
        if ($this->wordpress !== null && trim((string) ($representation['url'] ?? '')) === '') return ['state' => MediaSeoStateRegistry::METADATA_INCOMPLETE, 'eligible' => false, 'image_url' => null, 'media_id' => $media->canonicalId, 'asset_id' => $asset->assetId];
        return ['state' => MediaSeoStateRegistry::COMPLETE, 'eligible' => true, 'media_id' => $media->canonicalId, 'asset_id' => $asset->assetId, 'storage_key' => $asset->storageKey, 'image_url' => (string) ($representation['url'] ?? ''), 'src' => (string) ($representation['src'] ?? ''), 'srcset' => (string) ($representation['srcset'] ?? ''), 'sizes' => (string) ($representation['sizes'] ?? ''), 'width' => (int) ($representation['width'] ?? ($asset->width ?? 0)), 'height' => (int) ($representation['height'] ?? ($asset->height ?? 0)), 'alt' => (string) ($representation['alt'] ?? $usages[0]->altText)];
    }

    public function isImageSitemapEligible(string $endpointKey): bool
    {
        return $this->forPost($endpointKey)['eligible'] === true;
    }
}
