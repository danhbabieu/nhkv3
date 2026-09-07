<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Application\Entity\RelatedContentQuery;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
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
        private ?KnowledgeRepository $claims = null,
        private ?EvidenceRepository $evidence = null,
        private ?SourceRepository $sources = null,
    ) {
        $this->delivery ??= PublicMediaAssetDelivery::fromEnvironment($assets, $media);
        $this->gallery = $gallery ?? new PublicMediaGalleryQuery($media, $assets, $this->delivery);
    }

    public function mediaDetail(string $id): ?array
    {
        if (!$this->available('media') || !UuidCodec::isValid($id)) return null;
        $media = $this->media->findByCanonicalId($id);
        if (!$media || !$media->active || $media->readiness !== 'ready') return null;
        $assets = array_values(array_filter($this->assets->listByMediaId($id), fn (MediaAsset $asset): bool => $asset->visibility === 'PUBLIC' && ($this->delivery === null || $this->delivery->resolve($asset->assetId) !== null)));
        return [
            'name' => $media->canonicalName,
            'assets' => array_map($this->asset(...), $assets),
            'display_assets' => array_map($this->displayAsset(...), $assets),
            'usages' => array_map($this->usage(...), $this->usages->listByMediaId($id)),
        ];
    }

    public function mediaBySlug(string $slug): ?array { return null; }

    public function videoDetail(string $id): ?array
    {
        if (!$this->available('video') || !UuidCodec::isValid($id)) return null;
        $video = $this->videos->findByCanonicalId($id);
        if (!$video || !$video->active || !$video->hasValidPublicReference() || !$this->videoPublicEligible($video)) return null;
        return $this->video($video);
    }

    public function videoBySlug(string $slug): ?array
    {
        if (!$this->available('video')) return null;
        $slug = trim($slug);
        if ($slug === '') return null;
        $policy = new VideoUrlPolicy();
        $selector = new VideoPublicContextSelector();
        $matches = array_values(array_filter($this->videos->list(), fn (Video $video): bool => $video->active && ($result = $policy->project($video, $selector))['eligible'] && $result['path'] === '/video/' . $slug . '/'));
        return count($matches) === 1 ? $this->video($matches[0]) : null;
    }

    public function mediaArchive(int $page = 1, int $perPage = 24): array
    {
        return $this->available('media') ? $this->gallery->archive($page, $perPage) : ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'items' => []];
    }

    public function videoArchive(int $page = 1, int $perPage = 12): array
    {
        return $this->archive(
            $this->available('video') ? $this->videos->list() : [],
            $page,
            $perPage,
            fn (Video $item): array => $this->video($item),
            fn (object $item): bool => $item instanceof Video && $item->active && $item->hasValidPublicReference() && $this->videoPublicEligible($item),
        );
    }

    private function available(string $domain): bool { return !$this->status || ($domain === 'media' ? $this->status->mediaStorageReady() : $this->status->videoStorageReady()); }
    private function archive(array $items, int $page, int $perPage, callable $map, ?callable $filter = null): array { $page = max(1, $page); $perPage = min(100, max(1, $perPage)); $items = array_values(array_filter($items, $filter ?? static fn (object $item): bool => $item->active)); $items = array_map($map, $items); return ['page' => $page, 'per_page' => $perPage, 'total' => count($items), 'items' => array_slice($items, ($page - 1) * $perPage, $perPage)]; }

    private function videoPublicEligible(Video $video): bool
    {
        $result = (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector());
        return $result['eligible'] && is_string($result['path']) && $result['path'] !== '';
    }

    private function asset(MediaAsset $asset): array
    {
        return ['kind' => $asset->kind, 'mime_type' => $asset->mimeType, 'byte_size' => $asset->byteSize, 'width' => $asset->width, 'height' => $asset->height];
    }

    private function displayAsset(MediaAsset $asset): array
    {
        $item = $this->asset($asset);
        $filename = is_string($asset->metadata['canonical_filename'] ?? null) && trim((string) $asset->metadata['canonical_filename']) !== ''
            ? (string) $asset->metadata['canonical_filename']
            : basename(str_replace('\\', '/', $asset->storageKey));
        $item['public_url'] = $filename === '' ? null : (new PublicMediaAssetUrlResolver())->path($filename);
        return $item;
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
            'provenance' => $this->publicProvenance($metadata, $source),
            'knowledge' => $this->publicKnowledge($metadata),
            'internal_links' => $this->publicLinks($metadata['internal_links'] ?? []),
        ];
        if ($this->related !== null) $result['related'] = $this->related->forEntity('video', $video->canonicalId);
        return $result;
    }

    /** @return array<string,mixed> */
    private function publicProvenance(array $metadata, array $source): array
    {
        $provenance = is_array($metadata['provenance'] ?? null) ? $metadata['provenance'] : [];
        $locator = $provenance['locator'] ?? ($source['canonical_source_url'] ?? null);
        return array_filter(['kind' => $provenance['kind'] ?? null, 'origin' => $provenance['origin'] ?? null, 'locator' => $locator, 'platform' => $source['platform'] ?? 'youtube', 'external_id' => $source['external_video_id'] ?? null], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return list<array<string,mixed>> */
    private function publicKnowledge(array $metadata): array
    {
        if ($this->claims !== null && $this->evidence !== null && $this->sources !== null) {
            $claims = [];
            foreach ((array) ($metadata['semantic_attachments'] ?? []) as $attachment) {
                if (!is_array($attachment)) continue;
                foreach ((array) ($attachment['evidence_refs'] ?? []) as $reference) {
                    $evidenceId = is_array($reference) ? (string) ($reference['evidence_id'] ?? '') : (string) $reference;
                    if ($evidenceId === '') continue;
                    $evidence = $this->evidence->findByCanonicalId($evidenceId);
                    if ($evidence === null || !$evidence->active || !$evidence->isPublic()) continue;
                    $claim = $this->claims->findByCanonicalId($evidence->claimId);
                    $source = $this->sources->findByCanonicalId($evidence->sourceId);
                    if ($claim === null || !$claim->active || !$claim->isPublic() || $source === null || !$source->active || !$source->isPublic()) continue;
                    $claims[$claim->canonicalId] = ['id' => $claim->canonicalId, 'text' => $claim->claimText, 'type' => $claim->claimType, 'evidence' => ['excerpt' => $evidence->excerpt, 'relation' => $evidence->relation, 'source' => $source->title, 'locator' => $evidence->locator ?? $source->locator]];
                }
            }
            return array_values($claims);
        }
        $items = is_array($metadata['public_knowledge'] ?? null) ? $metadata['public_knowledge'] : [];
        return array_values(array_filter($items, static fn (mixed $item): bool => is_array($item) && trim((string) ($item['text'] ?? '')) !== ''));
    }

    /** @return list<array<string,mixed>> */
    private function publicLinks(mixed $links): array
    {
        if (!is_array($links)) return [];
        return array_values(array_filter($links, static function (mixed $item): bool {
            if (!is_array($item)) return false;
            $url = trim((string) ($item['url'] ?? ''));
            return filter_var($url, FILTER_VALIDATE_URL) !== false || str_starts_with($url, '/');
        }));
    }

    private function sourceThumbnail(array $source): ?string
    {
        $thumbnail = is_array($source['thumbnail_urls'] ?? null) ? (string) ($source['thumbnail_urls'][0] ?? '') : '';
        if ($thumbnail === '' || filter_var($thumbnail, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($thumbnail, PHP_URL_SCHEME)) !== 'https') return null;
        return $thumbnail;
    }
}
