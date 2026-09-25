<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Media\PublicMediaAssetUrlResolver;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Domain\Video\Video;

/** Resolves Video visual presentation without creating a second thumbnail owner. */
final class VideoMediaPresentationResolver
{
    public function __construct(
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private MediaUsageRepository $usages,
    ) {
    }

    /** @return array{status:string,status_label:string,media_id:?string,usage_id:?string,usage_revision:?int,thumbnail_url:?string,thumbnail:array<string,mixed>} */
    public function resolve(Video $video): array
    {
        $representatives = array_values(array_filter(
            $this->usages->listByEndpoint($video->canonicalId === '' ? 'video' : 'video', $video->canonicalId, MediaUsageRoleRegistry::REPRESENTATIVE),
            static fn (mixed $usage): bool => $usage instanceof MediaUsage && $usage->activeSlot !== 'retired',
        ));
        if ($representatives !== []) {
            $usage = $representatives[0];
            $media = $this->media->findByCanonicalId($usage->mediaId);
            $asset = $media instanceof Media ? $this->asset($media) : null;
            if ($asset instanceof MediaAsset) {
                $filename = (string) ($asset->metadata['canonical_filename'] ?? basename(str_replace('\\', '/', $asset->storageKey)));
                return [
                    'status' => 'representative',
                    'status_label' => 'Có ảnh đại diện',
                    'media_id' => $media->canonicalId,
                    'usage_id' => $usage->usageId,
                    'usage_revision' => $usage->revision,
                    'thumbnail_url' => (new PublicMediaAssetUrlResolver())->path($filename),
                    'thumbnail' => ['url' => (new PublicMediaAssetUrlResolver())->path($filename), 'media_id' => $media->canonicalId, 'usage_id' => $usage->usageId, 'width' => $asset->width, 'height' => $asset->height],
                ];
            }
        }

        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : (is_array($metadata['source'] ?? null) ? $metadata['source'] : []);
        $thumbnail = (new VideoThumbnailSelector())->presentationFromSource($source);
        if (($thumbnail['url'] ?? '') !== '') return ['status' => 'source', 'status_label' => 'Đang dùng ảnh nguồn', 'media_id' => null, 'usage_id' => null, 'usage_revision' => null, 'thumbnail_url' => (string) $thumbnail['url'], 'thumbnail' => $thumbnail];

        return ['status' => 'missing', 'status_label' => 'Thiếu ảnh đại diện', 'media_id' => null, 'usage_id' => null, 'usage_revision' => null, 'thumbnail_url' => null, 'thumbnail' => []];
    }

    private function asset(Media $media): ?MediaAsset
    {
        foreach ($this->assets->listByMediaId($media->canonicalId) as $asset) {
            if ($asset instanceof MediaAsset && $asset->visibility === 'PUBLIC') return $asset;
        }
        return null;
    }
}
