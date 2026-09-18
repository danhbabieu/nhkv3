<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaSeoStateRegistry, MediaUsage, MediaUsageRoleRegistry};
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
        private ?\Closure $attachmentReader = null,
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
        $metadata = $this->metadataFor($media, $image, $usages);
        $articleUrl = $this->articleLinks?->firstPublished($usages);
        return array_merge($metadata, [
            'image_url' => $image['image_url'] ?? null,
            'summary' => $this->summary($media),
            'width' => $image['width'] ?? null,
            'height' => $image['height'] ?? null,
            'has_real_image' => $image !== null,
            'article_url' => $articleUrl,
        ], $image === null
            ? ['state' => MediaSeoStateRegistry::MISSING, 'eligible' => false]
            : ['state' => MediaSeoStateRegistry::COMPLETE, 'eligible' => true]);
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
            'attachment_id' => (int) ($asset->metadata['wordpress_attachment_id'] ?? 0),
            'width' => $asset->width,
            'height' => $asset->height,
        ];
    }

    private function summary(Media $media): string
    {
        return 'Ảnh tư liệu trong kho hình ảnh NHK.';
    }

    /** @return list<\NHK\Core\Domain\Media\MediaUsage> */
    private function usagesForMedia(Media $media): array
    {
        if ($this->usages === null) return [];
        return array_values(array_filter($this->usages->listByMediaId($media->canonicalId), static fn (mixed $usage): bool => $usage instanceof \NHK\Core\Domain\Media\MediaUsage));
    }

    /** @return array{title:string,alt:string,caption:string,metadata_source:string} */
    /** @param list<MediaUsage> $usages */
    private function metadataFor(Media $media, ?array $image, array $usages): array
    {
        $permitted = array_values(array_filter($usages, static fn (MediaUsage $usage): bool => $usage->endpointType !== 'wp_post' && in_array($usage->role, [MediaUsageRoleRegistry::REPRESENTATIVE, MediaUsageRoleRegistry::EVIDENCE, MediaUsageRoleRegistry::TECHNICAL_DETAIL, 'gallery'], true)));
        usort($permitted, static fn (MediaUsage $left, MediaUsage $right): int => [$left->role === MediaUsageRoleRegistry::REPRESENTATIVE ? 0 : 1, $left->sortOrder, $left->usageId] <=> [$right->role === MediaUsageRoleRegistry::REPRESENTATIVE ? 0 : 1, $right->sortOrder, $right->usageId]);
        $values = [];
        $sources = [];
        foreach (['title', 'alt', 'caption'] as $field) {
            foreach ($permitted as $usage) {
                $value = trim((string) ($field === 'alt' ? $usage->altText : ($field === 'caption' ? $usage->caption : $usage->title)));
                if ($value === '') continue;
                $values[$field] = $value;
                $sources[] = $usage->role === MediaUsageRoleRegistry::REPRESENTATIVE ? 'SUBJECT_REPRESENTATIVE' : 'MEDIA_USAGE';
                break;
            }
            if (!array_key_exists($field, $values)) {
                $neutral = trim($media->canonicalName);
                if ($neutral !== '') {
                    $values[$field] = $neutral;
                    $sources[] = 'MEDIA_NEUTRAL';
                }
            }
            if (!array_key_exists($field, $values)) {
                $attachment = $this->attachmentMetadata($image);
                $attachmentValue = trim((string) ($attachment[$field] ?? ''));
                if ($attachmentValue !== '') {
                    $values[$field] = $attachmentValue;
                    $sources[] = 'WORDPRESS_ATTACHMENT';
                }
            }
            $values[$field] ??= '';
        }
        $rank = ['SUBJECT_REPRESENTATIVE' => 0, 'MEDIA_USAGE' => 1, 'MEDIA_NEUTRAL' => 2, 'WORDPRESS_ATTACHMENT' => 3];
        usort($sources, static fn (string $left, string $right): int => ($rank[$left] ?? 99) <=> ($rank[$right] ?? 99));
        return array_merge($values, ['metadata_source' => $sources[0] ?? 'MISSING']);
    }

    /** @param array<string,mixed>|null $image @return array<string,mixed> */
    private function attachmentMetadata(?array $image): array
    {
        $attachmentId = (int) ($image['attachment_id'] ?? 0);
        if ($attachmentId < 1 || $this->attachmentReader === null) return [];
        $metadata = ($this->attachmentReader)($attachmentId);
        return is_array($metadata) && (int) ($metadata['attachment_id'] ?? 0) === $attachmentId ? $metadata : [];
    }

    private function shorten(string $value): string
    {
        if (function_exists('wp_trim_words')) return trim((string) wp_trim_words($value, 24));
        $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return count($words) > 24 ? implode(' ', array_slice($words, 0, 24)) . '…' : $value;
    }
}
