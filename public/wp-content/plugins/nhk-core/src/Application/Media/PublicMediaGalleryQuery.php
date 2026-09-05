<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset};

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
    ) {}

    /** @return array{page:int,per_page:int,total:int,items:list<array<string,mixed>>} */
    public function archive(int $page = 1, int $perPage = 24): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $items = [];
        foreach ($this->media->list() as $media) {
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
        return [
            'title' => $media->canonicalName,
            'image_url' => $image['image_url'] ?? null,
            'alt' => $media->canonicalName,
            'width' => $image['width'] ?? null,
            'height' => $image['height'] ?? null,
            'has_real_image' => $image !== null,
        ];
    }

    /** @return array{image_url:string,width:?int,height:?int}|null */
    private function firstImage(Media $media): ?array
    {
        foreach ($this->assets->listByMediaId($media->canonicalId) as $asset) {
            if (!$asset instanceof MediaAsset || $asset->visibility !== 'PUBLIC' || !str_starts_with(strtolower($asset->mimeType), 'image/')) continue;
            if ($this->delivery !== null && $this->delivery->resolve($asset->assetId) === null) continue;
            $filename = is_string($asset->metadata['canonical_filename'] ?? null) && trim((string) $asset->metadata['canonical_filename']) !== ''
                ? (string) $asset->metadata['canonical_filename']
                : basename(str_replace('\\', '/', $asset->storageKey));
            if ($filename === '') continue;
            $path = (new PublicMediaAssetUrlResolver())->path($filename);
            return [
                'image_url' => function_exists('home_url') ? (string) home_url($path) : $path,
                'width' => $asset->width,
                'height' => $asset->height,
            ];
        }
        return null;
    }
}
