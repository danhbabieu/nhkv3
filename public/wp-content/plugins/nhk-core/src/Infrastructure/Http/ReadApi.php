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

final class ReadApi
{
    public function __construct(private MediaRepository $media, private MediaAssetRepository $assets, private MediaUsageRepository $usages, private VideoRepository $videos, private KnowledgeRepository $claims, private SourceRepository $sources, private EvidenceRepository $evidence, private ?MigrationStatus $status = null) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/media/(?P<id>[0-9a-f-]{36})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn (\WP_REST_Request $request) => $this->media($request)]);
        register_rest_route('nhk/v1', '/video/(?P<id>[0-9a-f-]{36})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn (\WP_REST_Request $request) => $this->video($request)]);
        register_rest_route('nhk/v1', '/knowledge/claim/(?P<id>[0-9a-f-]{36})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn (\WP_REST_Request $request) => $this->claim($request)]);
        register_rest_route('nhk/v1', '/knowledge/source/(?P<id>[0-9a-f-]{36})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn (\WP_REST_Request $request) => $this->source($request)]);
    }

    private function media(\WP_REST_Request $request): array|\WP_Error
    {
        if ($error = $this->unavailable(!$this->status || $this->status->mediaStorageReady(), 'media')) return $error;
        $media = $this->media->findByCanonicalId((string) $request['id']);
        if (!$media || !$media->active) return new \WP_Error('nhk_media_not_found', 'Media was not found.', ['status' => 404]);
        $assets = array_values(array_filter($this->assets->listByMediaId($media->canonicalId), static fn (MediaAsset $asset): bool => $asset->visibility === 'PUBLIC'));
        return ['id' => $media->canonicalId, 'stable_key' => $media->stableKey, 'name' => $media->canonicalName, 'readiness' => $media->readiness, 'active' => $media->active, 'revision' => $media->revision, 'provenance' => $media->provenance, 'assets' => array_map($this->asset(...), $assets), 'usages' => array_map($this->usage(...), $this->usages->listByMediaId($media->canonicalId))];
    }

    private function video(\WP_REST_Request $request): array|\WP_Error
    {
        if ($error = $this->unavailable(!$this->status || $this->status->videoStorageReady(), 'video')) return $error;
        $video = $this->videos->findByCanonicalId((string) $request['id']);
        if (!$video || !$video->active) return new \WP_Error('nhk_video_not_found', 'Video was not found.', ['status' => 404]);
        return ['id' => $video->canonicalId, 'platform' => $video->platform, 'external_id' => $video->externalVideoId, 'url' => $video->canonicalUrl, 'title' => $video->title, 'metadata' => $video->metadata, 'thumbnail_media_id' => $video->thumbnailMediaId, 'active' => $video->active, 'revision' => $video->revision];
    }

    private function claim(\WP_REST_Request $request): array|\WP_Error
    {
        if ($error = $this->unavailable(!$this->status || $this->status->knowledgeStorageReady(), 'knowledge')) return $error;
        $claim = $this->claims->findByCanonicalId((string) $request['id']);
        if (!$claim || !$claim->active) return new \WP_Error('nhk_claim_not_found', 'Knowledge claim was not found.', ['status' => 404]);
        return ['id' => $claim->canonicalId, 'stable_key' => $claim->stableKey, 'text' => $claim->claimText, 'type' => $claim->claimType, 'provenance' => $claim->provenance, 'active' => $claim->active, 'revision' => $claim->revision, 'evidence' => array_map($this->evidence(...), $this->publicEvidenceByClaim($claim->canonicalId))];
    }

    private function source(\WP_REST_Request $request): array|\WP_Error
    {
        if ($error = $this->unavailable(!$this->status || $this->status->knowledgeStorageReady(), 'knowledge')) return $error;
        $source = $this->sources->findByCanonicalId((string) $request['id']);
        if (!$source || !$source->active) return new \WP_Error('nhk_source_not_found', 'Source was not found.', ['status' => 404]);
        return ['id' => $source->canonicalId, 'stable_key' => $source->stableKey, 'title' => $source->title, 'type' => $source->sourceType, 'locator' => $source->locator, 'metadata' => $source->metadata, 'active' => $source->active, 'revision' => $source->revision, 'evidence' => array_map($this->evidence(...), array_values(array_filter($this->evidence->listBySource($source->canonicalId), function (Evidence $item): bool { if (!$item->active) return false; $claim = $this->claims->findByCanonicalId($item->claimId); return $claim !== null && $claim->active; })) )];
    }

    private function asset(MediaAsset $asset): array { return ['id' => $asset->assetId, 'kind' => $asset->kind, 'mime_type' => $asset->mimeType, 'byte_size' => $asset->byteSize, 'width' => $asset->width, 'height' => $asset->height]; }
    private function usage(MediaUsage $usage): array { return ['id' => $usage->usageId, 'endpoint_type' => $usage->endpointType, 'endpoint_key' => $usage->endpointKey, 'role' => $usage->role, 'sort_order' => $usage->sortOrder]; }
    private function evidence(Evidence $evidence): array { return ['id' => $evidence->canonicalId, 'claim_id' => $evidence->claimId, 'source_id' => $evidence->sourceId, 'relation' => $evidence->relation, 'excerpt' => $evidence->excerpt, 'locator' => $evidence->locator, 'metadata' => $evidence->metadata, 'active' => $evidence->active, 'revision' => $evidence->revision]; }
    private function publicEvidenceByClaim(string $claimId): array { return array_values(array_filter($this->evidence->listByClaim($claimId), function (Evidence $item): bool { if (!$item->active) return false; $source = $this->sources->findByCanonicalId($item->sourceId); return $source !== null && $source->active; })); }
    private function unavailable(bool $ready, string $domain): ?\WP_Error { return $ready ? null : new \WP_Error('nhk_storage_unavailable', ucfirst($domain) . ' storage is not ready.', ['status' => 503]); }
}
