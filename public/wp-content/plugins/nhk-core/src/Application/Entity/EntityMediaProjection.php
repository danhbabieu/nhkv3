<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Media\PublicMediaAssetUrlResolver;
use NHK\Core\Application\Media\PublicMediaAssetSelector;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage, MediaUsageRoleRegistry};

/** Read-only endpoint image projection; it never promotes usage into semantic truth. */
final class EntityMediaProjection
{
    public function __construct(private MediaRepository $media, private MediaAssetRepository $assets, private MediaUsageRepository $usages, private ?\Closure $attachmentReader = null) {}

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
        $asset = (new PublicMediaAssetSelector())->canonical($this->assets->listByMediaId($media->canonicalId));
        if (!$asset instanceof MediaAsset) return null;
        $filename = is_string($asset->metadata['canonical_filename'] ?? null) && trim((string) $asset->metadata['canonical_filename']) !== '' ? (string) $asset->metadata['canonical_filename'] : basename(str_replace('\\', '/', $asset->storageKey));
        if ($filename === '') return null;
        $path = (new PublicMediaAssetUrlResolver())->path($filename);
        $metadata = $this->metadataFor($usage, $media, $asset);
        return array_merge($metadata, ['media_id' => $media->canonicalId, 'asset_id' => $asset->assetId, 'stable_key' => $media->stableKey, 'url' => function_exists('home_url') ? (string) home_url($path) : $path, 'width' => $asset->width, 'height' => $asset->height, 'role' => $usage->role, 'placement' => $usage->placementKey, 'sort_order' => $usage->sortOrder]);
    }

    /** @return array{title:string,alt:string,caption:string,metadata_source:string} */
    private function metadataFor(MediaUsage $usage, Media $media, MediaAsset $asset): array
    {
        $values = [];
        $sources = [];
        $attachment = $this->attachmentMetadata($asset);
        foreach (['title', 'alt', 'caption'] as $field) {
            $usageValue = $field === 'alt' ? $usage->altText : ($field === 'caption' ? $usage->caption : $usage->title);
            $value = trim((string) $usageValue);
            $source = $value !== '' ? ($usage->role === MediaUsageRoleRegistry::REPRESENTATIVE ? 'SUBJECT_REPRESENTATIVE' : 'MEDIA_USAGE') : '';
            if ($value === '') { $value = trim((string) ($media->canonicalName)); $source = $value !== '' ? 'MEDIA_NEUTRAL' : ''; }
            if ($value === '') { $value = trim((string) ($attachment[$field] ?? '')); $source = $value !== '' ? 'WORDPRESS_ATTACHMENT' : ''; }
            $values[$field] = $value;
            if ($source !== '') $sources[] = $source;
        }
        $rank = ['SUBJECT_REPRESENTATIVE' => 0, 'MEDIA_USAGE' => 1, 'MEDIA_NEUTRAL' => 2, 'WORDPRESS_ATTACHMENT' => 3];
        usort($sources, static fn (string $left, string $right): int => ($rank[$left] ?? 99) <=> ($rank[$right] ?? 99));
        return array_merge($values, ['metadata_source' => $sources[0] ?? 'MISSING']);
    }

    /** @return array<string,mixed> */
    private function attachmentMetadata(MediaAsset $asset): array
    {
        $attachmentId = (int) ($asset->metadata['wordpress_attachment_id'] ?? 0);
        if ($attachmentId < 1) return [];
        if ($this->attachmentReader !== null) {
            $attachment = ($this->attachmentReader)($attachmentId);
            return is_array($attachment) && (int) ($attachment['attachment_id'] ?? 0) === $attachmentId ? $attachment : [];
        }
        if (!function_exists('get_post')) return [];
        $post = get_post($attachmentId);
        if (!is_object($post) || (string) ($post->post_type ?? '') !== 'attachment') return [];
        return [
            'attachment_id' => $attachmentId,
            'title' => trim((string) ($post->post_title ?? '')),
            'caption' => trim((string) ($post->post_excerpt ?? '')),
            'alt' => function_exists('get_post_meta') ? trim((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true)) : '',
        ];
    }
}
