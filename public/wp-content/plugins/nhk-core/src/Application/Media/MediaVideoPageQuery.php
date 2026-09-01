<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Migration\MigrationStatus;

final class MediaVideoPageQuery
{
    public function __construct(private MediaRepository $media, private MediaAssetRepository $assets, private MediaUsageRepository $usages, private VideoRepository $videos, private ?MigrationStatus $status = null, private ?PublicMediaAssetDelivery $delivery = null) { $this->delivery ??= PublicMediaAssetDelivery::fromEnvironment($assets, $media); }
    public function mediaDetail(string $id): ?array { if (!$this->available('media')) return null; $media = $this->media->findByCanonicalId($id); if (!$media || !$media->active || $media->readiness !== 'ready') return null; $assets = array_values(array_filter($this->assets->listByMediaId($id), fn (MediaAsset $asset): bool => $asset->visibility === 'PUBLIC' && ($this->delivery === null || $this->delivery->resolve($asset->assetId) !== null))); return ['id' => $media->canonicalId, 'name' => $media->canonicalName, 'stable_key' => $media->stableKey, 'assets' => array_map($this->asset(...), $assets), 'usages' => array_map($this->usage(...), $this->usages->listByMediaId($id))]; }
    public function videoDetail(string $id): ?array { if (!$this->available('video')) return null; $video = $this->videos->findByCanonicalId($id); return $video && $video->active && $video->hasValidPublicReference() ? ['id' => $video->canonicalId, 'title' => $video->title, 'platform' => $video->platform, 'external_id' => $video->externalVideoId, 'url' => $video->canonicalUrl] : null; }
    /** @return array{page:int,per_page:int,total:int,items:list<array<string,mixed>>} */
    public function mediaArchive(int $page = 1, int $perPage = 24): array { return $this->archive($this->available('media') ? $this->media->list() : [], $page, $perPage, fn (Media $item): array => ['id' => $item->canonicalId, 'title' => $item->canonicalName, 'stable_key' => $item->stableKey], static fn (object $item): bool => $item->active && $item->readiness === 'ready'); }
    /** @return array{page:int,per_page:int,total:int,items:list<array<string,mixed>>} */
    public function videoArchive(int $page = 1, int $perPage = 12): array { return $this->archive($this->available('video') ? $this->videos->list() : [], $page, $perPage, fn (Video $item): array => ['id' => $item->canonicalId, 'title' => $item->title, 'platform' => $item->platform, 'url' => $item->canonicalUrl], static fn (object $item): bool => $item->active && $item->hasValidPublicReference()); }
    private function available(string $domain): bool { return !$this->status || ($domain === 'media' ? $this->status->mediaStorageReady() : $this->status->videoStorageReady()); }
    private function archive(array $items, int $page, int $perPage, callable $map, ?callable $filter = null): array { $page = max(1, $page); $perPage = min(100, max(1, $perPage)); $items = array_values(array_filter($items, $filter ?? static fn (object $item): bool => $item->active)); $items = array_map($map, $items); return ['page' => $page, 'per_page' => $perPage, 'total' => count($items), 'items' => array_slice($items, ($page - 1) * $perPage, $perPage)]; }
    private function asset(MediaAsset $asset): array { return ['id' => $asset->assetId, 'kind' => $asset->kind, 'mime_type' => $asset->mimeType, 'byte_size' => $asset->byteSize, 'width' => $asset->width, 'height' => $asset->height, 'public_url' => '/media/asset/' . $asset->assetId . '/']; }
    private function usage(MediaUsage $usage): array { return ['id' => $usage->usageId, 'role' => $usage->role, 'sort_order' => $usage->sortOrder]; }
}
