<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Application\Presentation\LatestFirstOrder;

/**
 * Read-only visitor projection for real public image assets.
 *
 * Media itself has no public detail route. This projection therefore exposes
 * display data only and never invents a Media permalink or semantic relation.
 */
final class PublicMediaGalleryQuery
{
    public function __construct(
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private ?PublicMediaAssetDelivery $delivery = null,
        private ?MediaUsageRepository $usages = null,
        private ?PublicMediaArticleLinkResolver $articleLinks = null,
    ) {}

    /** @return array{page:int,per_page:int,total:int,items:list<array<string,mixed>>} */
    public function archive(int $page = 1, int $perPage = 24): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $items = [];
        $mediaItems = LatestFirstOrder::sort($this->media->list(), static fn (Media $item): ?string => null, static fn (Media $item): ?string => $item->createdAt, static fn (Media $item): string => $item->canonicalId);
        foreach ($mediaItems as $media) {
            $item = $this->card($media);
            if ($item !== null) $items[] = $item;
        }
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => count($items),
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
        ];
    }

    /** @return array<string,mixed>|null */
    public function forMedia(string $canonicalId): ?array
    {
        $media = $this->media->findByCanonicalId($canonicalId);
        return $media instanceof Media ? $this->card($media) : null;
    }

    /** @return array<string,mixed>|null */
    private function card(Media $media): ?array
    {
        if (!$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return null;
        $image = $this->firstImage($media);
        $usages = $this->usagesForMedia($media);
        $contextual = $this->preferredUsage($usages);
        $metadata = $this->metadataFor($contextual, $media);
        $articleUrl = $this->articleLinks?->firstPublished($usages);
        return array_merge($metadata, [
            'image_url' => $image['image_url'] ?? null,
            'summary' => $this->summary($media),
            'width' => $image['width'] ?? null,
            'height' => $image['height'] ?? null,
            'has_real_image' => $image !== null,
            'article_url' => $articleUrl,
            'eligible' => $image !== null,
            'state' => $image !== null ? 'COMPLETE' : 'MISSING',
            'url' => $image['image_url'] ?? null,
        ]);
    }

    /** @return array{image_url:string,width:?int,height:?int}|null */
    private function firstImage(Media $media): ?array
    {
        $asset = (new PublicMediaAssetSelector())->canonical($this->assets->listByMediaId($media->canonicalId));
        // The read model may expose a governed public projection even when a
        // request-time binary check is temporarily unavailable. The /anh/
        // delivery route remains the fail-closed binary boundary.
        if (!$asset instanceof MediaAsset) return null;
        $filename = is_string($asset->metadata['canonical_filename'] ?? null) && trim((string) $asset->metadata['canonical_filename']) !== ''
            ? (string) $asset->metadata['canonical_filename']
            : basename(str_replace('\\', '/', $asset->storageKey));
        if ($filename === '') return null;
        $path = (new PublicMediaAssetUrlResolver())->path($filename);
        return [
            'image_url' => function_exists('home_url') ? (string) home_url($path) : $path,
            'width' => $asset->width,
            'height' => $asset->height,
        ];
    }

    private function summary(Media $media): string
    {
        foreach ($this->usagesForMedia($media) as $usage) {
            $caption = trim(preg_replace('/\s+/u', ' ', $usage->caption) ?? '');
            if ($caption !== '') return $this->shorten($caption);
        }
        return 'Ảnh tư liệu trong kho hình ảnh NHK.';
    }

    /** @return list<\NHK\Core\Domain\Media\MediaUsage> */
    private function usagesForMedia(Media $media): array
    {
        if ($this->usages === null) return [];
        return array_values(array_filter($this->usages->listByMediaId($media->canonicalId), static fn (mixed $usage): bool => $usage instanceof \NHK\Core\Domain\Media\MediaUsage));
    }

    /** @param list<MediaUsage> $usages */
    private function preferredUsage(array $usages): ?MediaUsage
    {
        foreach ([MediaUsageRoleRegistry::REPRESENTATIVE, MediaUsageRoleRegistry::FEATURED_PRIMARY] as $role) {
            foreach ($usages as $usage) if ($usage->role === $role) return $usage;
        }
        return $usages[0] ?? null;
    }

    /** @return array{title:string,alt:string,caption:string,metadata_source:string} */
    private function metadataFor(?MediaUsage $usage, Media $media): array
    {
        $values = [];
        $usedUsage = false;
        foreach (['title', 'alt', 'caption'] as $field) {
            $usageValue = $usage === null ? '' : ($field === 'alt' ? $usage->altText : ($field === 'caption' ? $usage->caption : $usage->title));
            $value = trim((string) $usageValue);
            if ($value !== '') {
                $usedUsage = true;
                $values[$field] = $value;
            } else {
                $values[$field] = $media->canonicalName;
            }
        }
        return array_merge($values, ['metadata_source' => $usedUsage ? ($usage?->role === MediaUsageRoleRegistry::REPRESENTATIVE ? 'SUBJECT_REPRESENTATIVE' : 'MEDIA_USAGE') : 'MEDIA_NEUTRAL']);
    }

    private function shorten(string $value): string
    {
        if (function_exists('wp_trim_words')) return trim((string) wp_trim_words($value, 24));
        $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return count($words) > 24 ? implode(' ', array_slice($words, 0, 24)) . '…' : $value;
    }
}
