<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository, WordPressArticleMediaAdapter};
use NHK\Core\Domain\Media\{MediaAsset, MediaSeoStateRegistry, MediaUsageRoleRegistry};

final class ArticleMediaSeoProjection
{
    public function __construct(private MediaRepository $media, private MediaAssetRepository $assets, private MediaUsageRepository $usages, private ?WordPressArticleMediaAdapter $wordpress = null) {}

    /** @return array<string,mixed> */
    public function forPost(string $endpointKey): array
    {
        $usages = $this->usages->listByEndpoint('wp_post', $endpointKey, MediaUsageRoleRegistry::FEATURED_PRIMARY);
        if (count($usages) !== 1) return $this->missing(MediaSeoStateRegistry::INCOMPLETE_FEATURED);
        $usage = $usages[0];
        $media = $this->media->findByCanonicalId($usages[0]->mediaId);
        if ($media === null || !$media->active || $media->isSystemPlaceholder()) return $this->missing(MediaSeoStateRegistry::PLACEHOLDER, $usage);
        $assets = $this->assets->listByMediaId($media->canonicalId);
        $asset = (new PublicMediaAssetSelector())->canonical($assets);
        if (!$asset instanceof MediaAsset) {
            return $this->missing('MISSING', $usage, $media);
        }
        $representation = [];
        if ($this->wordpress !== null) {
            try { $representation = $this->wordpress->attachmentForMedia($media, $asset, (string) ($usage->altText ?? '')); } catch (\Throwable) { $representation = []; }
        }
        $metadata = $this->metadataFor($usage, $media, $representation);
        $canonical = (new PublicMediaAssetUrlResolver())->path(is_string($asset->metadata['canonical_filename'] ?? null) ? $asset->metadata['canonical_filename'] : basename($asset->storageKey));
        $url = function_exists('home_url') ? home_url($canonical) : $canonical;
        return array_merge($metadata, ['state' => MediaSeoStateRegistry::COMPLETE, 'eligible' => true, 'media_id' => $media->canonicalId, 'asset_id' => $asset->assetId, 'storage_key' => $asset->storageKey, 'url' => $url, 'image_url' => $url, 'src' => $url, 'srcset' => function_exists('home_url') ? home_url($canonical) . ' ' . (int) ($asset->width ?? 0) . 'w' : $canonical, 'sizes' => (string) ($representation['sizes'] ?? ''), 'width' => (int) ($asset->width ?? ($representation['width'] ?? 0)), 'height' => (int) ($asset->height ?? ($representation['height'] ?? 0))]);
    }

    public function isImageSitemapEligible(string $endpointKey): bool
    {
        return $this->forPost($endpointKey)['eligible'] === true;
    }

    /** @return array<string,mixed> */
    private function metadataFor(\NHK\Core\Domain\Media\MediaUsage $usage, \NHK\Core\Domain\Media\Media $media, array $attachment): array
    {
        $fields = ['title', 'alt', 'caption'];
        $values = [];
        $usedSources = [];
        foreach ($fields as $field) {
            $candidates = [
                ['value' => $field === 'alt' ? $usage->altText : ($field === 'caption' ? $usage->caption : $usage->title), 'source' => 'MEDIA_USAGE'],
                ['value' => $attachment[$field] ?? '', 'source' => 'WORDPRESS_ATTACHMENT'],
                ['value' => $media->canonicalName, 'source' => 'MEDIA_NEUTRAL'],
            ];
            foreach ($candidates as $candidate) {
                $value = trim((string) $candidate['value']);
                if ($value === '') continue;
                $values[$field] = $value;
                $usedSources[] = $candidate['source'];
                break;
            }
            $values[$field] ??= '';
        }
        $values['metadata_source'] = in_array('MEDIA_USAGE', $usedSources, true)
            ? 'MEDIA_USAGE'
            : (in_array('WORDPRESS_ATTACHMENT', $usedSources, true) ? 'WORDPRESS_ATTACHMENT' : (in_array('MEDIA_NEUTRAL', $usedSources, true) ? 'MEDIA_NEUTRAL' : 'MISSING'));
        return $values;
    }

    /** @return array<string,mixed> */
    private function missing(string $state, ?\NHK\Core\Domain\Media\MediaUsage $usage = null, ?\NHK\Core\Domain\Media\Media $media = null): array
    {
        $title = $usage !== null && trim($usage->title) !== '' ? $usage->title : ($media?->canonicalName ?? '');
        return ['state' => $state, 'eligible' => false, 'url' => null, 'image_url' => null, 'title' => $title, 'alt' => $usage?->altText ?? '', 'caption' => $usage?->caption ?? '', 'metadata_source' => $usage !== null ? 'MEDIA_USAGE' : 'MISSING'];
    }
}
