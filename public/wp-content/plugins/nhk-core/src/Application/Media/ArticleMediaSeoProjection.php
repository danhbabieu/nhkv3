<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{ArticleMediaBlueprintRepository, MediaAssetRepository, MediaRepository, MediaUsageRepository, WordPressArticleMediaAdapter};
use NHK\Core\Domain\Media\{MediaAsset, MediaSeoStateRegistry, MediaUsageRoleRegistry};

final class ArticleMediaSeoProjection
{
    public function __construct(private MediaRepository $media, private MediaAssetRepository $assets, private MediaUsageRepository $usages, private ?WordPressArticleMediaAdapter $wordpress = null, private ?SemanticSuitabilityPolicy $suitabilityPolicy = null, private ?ArticleMediaBlueprintRepository $blueprints = null) {}

    /** @return array<string,mixed> */
    public function forPost(string $endpointKey): array
    {
        $usages = $this->usages->listByEndpoint('wp_post', $endpointKey, MediaUsageRoleRegistry::FEATURED_PRIMARY);
        if (count($usages) !== 1) return ['state' => MediaSeoStateRegistry::INCOMPLETE_FEATURED, 'eligible' => false, 'image_url' => null];
        $media = $this->media->findByCanonicalId($usages[0]->mediaId);
        if ($media === null || !$media->active || $media->isSystemPlaceholder()) return ['state' => MediaSeoStateRegistry::PLACEHOLDER, 'eligible' => false, 'image_url' => null];
        $assets = $this->assets->listByMediaId($media->canonicalId);
        $postId = (int) preg_replace('/^.*:/', '', $endpointKey);
        $blueprint = $postId > 0 ? $this->blueprints?->findByPostAndSlot($postId, MediaUsageRoleRegistry::FEATURED_PRIMARY) : null;
        $subjectIds = [];
        if ($blueprint !== null) foreach (['subject_ids', 'canonical_subject_ids'] as $key) foreach ((array) ($blueprint->subjectContext[$key] ?? []) as $subjectId) if (trim((string) $subjectId) !== '') $subjectIds[] = trim((string) $subjectId);
        if ($subjectIds !== []) {
            $assessment = ($this->suitabilityPolicy ??= new SemanticSuitabilityPolicy())->evaluateMedia($media, $assets, ['subject_ids' => array_values(array_unique($subjectIds))], 'SYSTEM_AUTO', MediaUsageRoleRegistry::FEATURED_PRIMARY);
            if (($assessment['valid_for_completeness'] ?? false) !== true) return ['state' => MediaSeoStateRegistry::INCOMPLETE_FEATURED, 'eligible' => false, 'image_url' => null, 'diagnostic' => $assessment['diagnostic'] ?? 'MEDIA_USAGE_SEMANTIC_MISMATCH'];
        }
        $asset = (new PublicMediaAssetSelector())->canonical($assets);
        if (!$asset instanceof MediaAsset) {
            $hasPublicAsset = array_filter($assets, static fn (mixed $candidate): bool => $candidate instanceof MediaAsset && $candidate->visibility === 'PUBLIC');
            return ['state' => $hasPublicAsset !== [] ? MediaSeoStateRegistry::LOW_RESOLUTION : MediaSeoStateRegistry::METADATA_INCOMPLETE, 'eligible' => false, 'image_url' => null];
        }
        $representation = [];
        if ($this->wordpress !== null) {
            try { $representation = $this->wordpress->attachmentForMedia($media, $asset, (string) ($usages[0]->altText ?? '')); } catch (\Throwable) { $representation = []; }
        }
        $canonical = (new PublicMediaAssetUrlResolver())->path(is_string($asset->metadata['canonical_filename'] ?? null) ? $asset->metadata['canonical_filename'] : basename($asset->storageKey));
        return ['state' => MediaSeoStateRegistry::COMPLETE, 'eligible' => true, 'media_id' => $media->canonicalId, 'asset_id' => $asset->assetId, 'storage_key' => $asset->storageKey, 'image_url' => function_exists('home_url') ? home_url($canonical) : $canonical, 'src' => function_exists('home_url') ? home_url($canonical) : $canonical, 'srcset' => function_exists('home_url') ? home_url($canonical) . ' ' . (int) ($asset->width ?? 0) . 'w' : $canonical, 'sizes' => (string) ($representation['sizes'] ?? ''), 'width' => (int) ($asset->width ?? ($representation['width'] ?? 0)), 'height' => (int) ($asset->height ?? ($representation['height'] ?? 0)), 'alt' => (string) ($representation['alt'] ?? $usages[0]->altText)];
    }

    public function isImageSitemapEligible(string $endpointKey): bool
    {
        return $this->forPost($endpointKey)['eligible'] === true;
    }
}
