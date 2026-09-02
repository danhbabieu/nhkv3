<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Application\Media\PublicMediaAssetDelivery;
use NHK\Core\Application\Entity\PublicRouteResolver;

final class ReadApi
{
    public function __construct(private MediaRepository $media, private MediaAssetRepository $assets, private MediaUsageRepository $usages, private VideoRepository $videos, private KnowledgeRepository $claims, private SourceRepository $sources, private EvidenceRepository $evidence, private ?MigrationStatus $status = null, private ?PublicMediaAssetDelivery $delivery = null) { $this->delivery ??= PublicMediaAssetDelivery::fromEnvironment($assets, $media); }

    public function register(): void
    {
        register_rest_route('nhk/v1', '/media/(?P<key>[a-z0-9][a-z0-9._:-]{0,190})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn (\WP_REST_Request $request) => $this->media($request)]);
        register_rest_route('nhk/v1', '/video/(?P<slug>[a-z0-9_-]{1,190})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn (\WP_REST_Request $request) => $this->video($request)]);
        register_rest_route('nhk/v1', '/knowledge/claim/(?P<key>[a-z0-9][a-z0-9._:-]{0,190})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn (\WP_REST_Request $request) => $this->claim($request)]);
        register_rest_route('nhk/v1', '/knowledge/source/(?P<key>[a-z0-9][a-z0-9._:-]{0,190})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn (\WP_REST_Request $request) => $this->source($request)]);
    }

    private function media(\WP_REST_Request $request): array|\WP_Error
    {
        if ($error = $this->unavailable(!$this->status || $this->status->mediaStorageReady(), 'media')) return $error;
        $media = $this->media->findByStableKey((string) $request['key']);
        if (!$media || !$media->active || $media->readiness !== 'ready') return new \WP_Error('nhk_media_not_found', 'Media was not found.', ['status' => 404]);
        $assets = array_values(array_filter($this->assets->listByMediaId($media->canonicalId), fn (MediaAsset $asset): bool => $asset->visibility === 'PUBLIC' && ($this->delivery === null || $this->delivery->resolve($asset->assetId) !== null)));
        return ['name' => $media->canonicalName, 'assets' => array_map($this->asset(...), $assets), 'usages' => array_map($this->usage(...), $this->usages->listByMediaId($media->canonicalId))];
    }

    private function video(\WP_REST_Request $request): array|\WP_Error
    {
        if ($error = $this->unavailable(!$this->status || $this->status->videoStorageReady(), 'video')) return $error;
        $slug = trim((string) $request['slug']);
        $matches = array_values(array_filter($this->videos->list(), fn (Video $item): bool => $item->active && $item->hasValidPublicReference() && PublicRouteResolver::videoPath($item->title, $item->externalVideoId) === '/' . $slug . '/'));
        $video = count($matches) === 1 ? $matches[0] : null;
        if (!$video || !$video->active || !$video->hasValidPublicReference()) return new \WP_Error('nhk_video_not_found', 'Video was not found.', ['status' => 404]);
        return ['platform' => $video->platform, 'external_id' => $video->externalVideoId, 'url' => $video->canonicalUrl, 'title' => $video->title];
    }

    private function claim(\WP_REST_Request $request): array|\WP_Error
    {
        if ($error = $this->unavailable(!$this->status || $this->status->knowledgeStorageReady(), 'knowledge')) return $error;
        $claim = $this->claims->findByStableKey((string) $request['key']);
        if (!$claim || !$claim->active || !$claim->isPublic()) return new \WP_Error('nhk_claim_not_found', 'Knowledge claim was not found.', ['status' => 404]);
        return ['text' => $claim->claimText, 'type' => $claim->claimType, 'evidence' => array_map($this->evidence(...), $this->publicEvidenceByClaim($claim->canonicalId))];
    }

    private function source(\WP_REST_Request $request): array|\WP_Error
    {
        if ($error = $this->unavailable(!$this->status || $this->status->knowledgeStorageReady(), 'knowledge')) return $error;
        $source = $this->sources->findByStableKey((string) $request['key']);
        if (!$source || !$source->active || !$source->isPublic()) return new \WP_Error('nhk_source_not_found', 'Source was not found.', ['status' => 404]);
        return ['title' => $source->title, 'type' => $source->sourceType, 'locator' => $source->locator, 'evidence' => array_map($this->evidence(...), array_values(array_filter($this->evidence->listBySource($source->canonicalId), function (Evidence $item): bool { if (!$item->active || !$item->isPublic()) return false; $claim = $this->claims->findByCanonicalId($item->claimId); return $claim !== null && $claim->active && $claim->isPublic(); })) )];
    }

    private function asset(MediaAsset $asset): array { return ['kind' => $asset->kind, 'mime_type' => $asset->mimeType, 'byte_size' => $asset->byteSize, 'width' => $asset->width, 'height' => $asset->height]; }
    private function usage(MediaUsage $usage): array { return ['role' => $usage->role, 'sort_order' => $usage->sortOrder, 'alt' => $usage->altText, 'caption' => $usage->caption]; }
    private function evidence(Evidence $evidence): array { return ['relation' => $evidence->relation, 'excerpt' => $evidence->excerpt, 'locator' => $evidence->locator]; }
    private function publicEvidenceByClaim(string $claimId): array { return array_values(array_filter($this->evidence->listByClaim($claimId), function (Evidence $item): bool { if (!$item->active || !$item->isPublic()) return false; $source = $this->sources->findByCanonicalId($item->sourceId); return $source !== null && $source->active && $source->isPublic(); })); }
    private function unavailable(bool $ready, string $domain): ?\WP_Error { return $ready ? null : new \WP_Error('nhk_storage_unavailable', ucfirst($domain) . ' storage is not ready.', ['status' => 503]); }
}
