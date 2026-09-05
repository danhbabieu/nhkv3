<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Application\Entity\RelatedContentQuery;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Video\{VideoPublicContextSelector, VideoSeoProjection, VideoUrlPolicy};

final class MediaVideoPageQuery
{
    private PublicMediaGalleryQuery $gallery;

    public function __construct(
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private MediaUsageRepository $usages,
        private VideoRepository $videos,
        private ?MigrationStatus $status = null,
        private ?PublicMediaAssetDelivery $delivery = null,
        private ?RelatedContentQuery $related = null,
        ?PublicMediaGalleryQuery $gallery = null,
    ) {
        $this->delivery ??= PublicMediaAssetDelivery::fromEnvironment($assets, $media);
        $this->gallery = $gallery ?? new PublicMediaGalleryQuery($media, $assets, $this->delivery);
    }

    public function mediaDetail(string $id): ?array { if (!$this->available('media') || !UuidCodec::isValid($id)) return null; $media = $this->media->findByCanonicalId($id); if (!$media || !$media->active || $media->readiness !== 'ready') return null; $assets = array_values(array_filter($this->assets->listByMediaId($id), fn (MediaAsset $asset): bool => $asset->visibility === 'PUBLIC' && ($this->delivery === null || $this->delivery->resolve($asset->assetId) !== null))); return ['name' => $media->canonicalName, 'assets' => array_map($this->asset(...), $assets), 'usages' => array_map($this->usage(...), $this->usages->listByMediaId($id))]; }
    public function mediaBySlug(string $slug): ?array { return null; }
    public function videoDetail(string $id): ?array { if (!$this->available('video') || !UuidCodec::isValid($id)) return null; $video = $this->videos->findByCanonicalId($id); return $video && $video->active && $video->hasValidPublicReference() ? $this->video($video) : null; }
    public function videoBySlug(string $slug): ?array { if (!$this->available('video')) return null; $slug = trim($slug); $policy = new VideoUrlPolicy(); $selector = new VideoPublicContextSelector(); $matches = array_values(array_filter($this->videos->list(), fn (Video $video): bool => $video->active && ($result = $policy->project($video, $selector))['eligible'] && $result['path'] === '/' . $slug . '/')); return count($matches) === 1 ? $this->video($matches[0]) : null; }

    /** @return array{page:int,per_page:int,total:int,items:list<array<string,mixed>>} */
    public function mediaArchive(int $page = 1, int $perPage = 24): array
    {
        return $this->available('media') ? $this->gallery->archive($page, $perPage) : ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'items' => []];
    }

    /** @return array{page:int,per_page:int,total:int,items:list<array<string,mixed>>} */
    public function videoArchive(int $page = 1, int $perPage = 12): array { return $this->archive($this->available('video') ? $this->videos->list() : [], $page, $perPage, fn (Video $item): array => $this->video($item), static fn (object $item): bool => $item->active && $item->hasValidPublicReference()); }
    private function available(string $domain): bool { return !$this->status || ($domain === 'media' ? $this->status->mediaStorageReady() : $this->status->videoStorageReady()); }
    private function archive(array $items, int $page, int $perPage, callable $map, ?callable $filter = null): array { $page = max(1, $page); $perPage = min(100, max(1, $perPage)); $items = array_values(array_filter($items, $filter ?? static fn (object $item): bool => $item->active)); $items = array_map($map, $items); return ['page' => $page, 'per_page' => $perPage, 'total' => count($items), 'items' => array_slice($items, ($page - 1) * $perPage, $perPage)]; }

    private function asset(MediaAsset $asset): array
    {
        $filename = is_string($asset->metadata['canonical_filename'] ?? null) && trim((string) $asset->metadata['canonical_filename']) !== ''
            ? (string) $asset->metadata['canonical_filename']
            : basename(str_replace('\\', '/', $asset->storageKey));
        $publicUrl = $filename === '' ? null : (new PublicMediaAssetUrlResolver())->path($filename);
        return ['kind' => $asset->kind, 'mime_type' => $asset->mimeType, 'byte_size' => $asset->byteSize, 'width' => $asset->width, 'height' => $asset->height, 'public_url' => $publicUrl];
    }

    private function usage(MediaUsage $usage): array { return ['role' => $usage->role, 'sort_order' => $usage->sortOrder, 'alt' => $usage->altText, 'caption' => $usage->caption]; }

    private function video(Video $video): array
    {
        $metadata = $video->metadata;
        $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : [];
        $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
        $category = is_array($metadata['category'] ?? null) ? $metadata['category'] : [];
        $sourceAvailable = !isset($source['availability']) || $source['availability'] === 'available';
        $urlResult = (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector());
        $publicUrl = $urlResult['path'];
        $seoProjection = null;
        if ($urlResult['eligible'] && $sourceAvailable && ($source['availability'] ?? 'unknown') === 'available') {
            $seoProjection = is_array($metadata['seo_projection'] ?? null) ? $metadata['seo_projection'] : (new VideoSeoProjection())->project(['source' => array_merge($source, ['external_video_id' => $video->externalVideoId]), 'editorial' => $editorial, 'seo' => is_array($metadata['seo'] ?? null) ? $metadata['seo'] : []], function_exists('home_url') ? home_url((string) $publicUrl) : (string) $publicUrl);
        }
        $result = [
            'title' => (string) ($editorial['title'] ?? $video->title),
            'summary' => (string) ($editorial['summary'] ?? ''),
            'body' => (string) ($editorial['body'] ?? ''),
            'why_this_matters' => (string) ($editorial['why_this_matters'] ?? ''),
            'category' => is_array($category['primary'] ?? null) ? $category['primary'] : null,
            'platform' => $video->platform,
            'external_id' => $video->externalVideoId,
            'url' => $video->canonicalUrl,
            'public_url' => $publicUrl,
            'embed_url' => $sourceAvailable ? 'https://www.youtube-nocookie.com/embed/' . $video->externalVideoId : null,
            'source_available' => $sourceAvailable,
            'source_thumbnail_url' => $this->sourceThumbnail($source),
            'source_status' => (string) ($source['availability'] ?? 'unknown'),
            'seo_projection' => $seoProjection,
        ];
        if ($this->related !== null) $result['related'] = $this->related->forEntity('video', $video->canonicalId);
        return $result;
    }

    private function sourceThumbnail(array $source): ?string
    {
        $thumbnail = is_array($source['thumbnail_urls'] ?? null) ? (string) ($source['thumbnail_urls'][0] ?? '') : '';
        if ($thumbnail === '' || filter_var($thumbnail, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($thumbnail, PHP_URL_SCHEME)) !== 'https') return null;
        return $thumbnail;
    }
}
