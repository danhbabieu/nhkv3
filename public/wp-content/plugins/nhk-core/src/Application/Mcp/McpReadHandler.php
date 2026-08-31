<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Migration\MigrationStatus;

final class McpReadHandler
{
    public function __construct(
        private AuthorityRepository $authority,
        private EntityTypeRegistry $types,
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private MediaUsageRepository $usages,
        private VideoRepository $videos,
        private KnowledgeRepository $claims,
        private EvidenceRepository $evidence,
        private ?MigrationStatus $status = null,
    ) {}

    public function entityGet(string $type, string $id): ?array
    {
        if (!$this->types->has($type) || !$this->ready('authority')) return null;
        $entity = $this->authority->findByCanonicalId($id);
        return $entity && $entity->entityType === $type ? $this->entity($entity) : null;
    }

    public function mediaGet(string $id): ?array
    {
        if (!$this->ready('media')) return null;
        $media = $this->media->findByCanonicalId($id);
        if (!$media) return null;
        return ['id' => $media->canonicalId, 'stable_key' => $media->stableKey, 'name' => $media->canonicalName, 'readiness' => $media->readiness, 'active' => $media->active, 'revision' => $media->revision, 'provenance' => $media->provenance, 'assets' => array_map($this->asset(...), $this->assets->listByMediaId($id)), 'usages' => array_map($this->usage(...), $this->usages->listByMediaId($id))];
    }

    public function videoGet(string $id): ?array
    {
        if (!$this->ready('video')) return null;
        $video = $this->videos->findByCanonicalId($id);
        return $video ? ['id' => $video->canonicalId, 'platform' => $video->platform, 'external_id' => $video->externalVideoId, 'url' => $video->canonicalUrl, 'title' => $video->title, 'metadata' => $video->metadata, 'thumbnail_media_id' => $video->thumbnailMediaId, 'active' => $video->active, 'revision' => $video->revision] : null;
    }

    public function knowledgeGet(string $id): ?array
    {
        if (!$this->ready('knowledge')) return null;
        $claim = $this->claims->findByCanonicalId($id);
        return $claim ? ['id' => $claim->canonicalId, 'stable_key' => $claim->stableKey, 'text' => $claim->claimText, 'type' => $claim->claimType, 'provenance' => $claim->provenance, 'active' => $claim->active, 'revision' => $claim->revision, 'evidence' => array_map($this->evidence(...), $this->evidence->listByClaim($id))] : null;
    }

    public function search(string $term, int $page = 1, int $perPage = 20): array
    {
        $term = trim($term); $length = function_exists('mb_strlen') ? mb_strlen($term) : strlen($term);
        if ($length < 2 || $length > 120) throw new \InvalidArgumentException('Search term must contain 2–120 characters.');
        $page = max(1, $page); $perPage = min(50, max(1, $perPage));
        $posts = [];
        if (class_exists('WP_Query')) {
            $query = new \WP_Query(['post_type' => 'post', 'post_status' => 'publish', 's' => $term, 'posts_per_page' => $perPage, 'paged' => $page, 'ignore_sticky_posts' => true]);
            $posts = array_map(static fn (\WP_Post $post): array => ['type' => 'post', 'id' => (string) $post->ID, 'title' => get_the_title($post), 'url' => get_permalink($post), 'excerpt' => wp_trim_words(wp_strip_all_tags(get_the_excerpt($post)), 28), 'date' => get_the_date('c', $post)], $query->posts);
            $postTotal = (int) $query->found_posts;
        } else $postTotal = 0;
        $groups = ['posts' => $posts, 'entities' => [], 'media' => [], 'videos' => [], 'knowledge' => []];
        if ($this->ready('authority')) foreach ($this->types->all() as $definition) foreach ($this->authority->listByType($definition->type) as $entity) if ($this->matches($term, $entity->canonicalName, $entity->stableKey, $this->json($entity->payload))) $groups['entities'][] = ['type' => $entity->entityType, 'id' => $entity->canonicalId, 'title' => $entity->canonicalName, 'stable_key' => $entity->stableKey];
        if ($this->ready('media')) foreach ($this->media->list() as $media) if ($this->matches($term, $media->canonicalName, $media->stableKey)) $groups['media'][] = ['type' => 'media', 'id' => $media->canonicalId, 'title' => $media->canonicalName, 'stable_key' => $media->stableKey];
        if ($this->ready('video')) foreach ($this->videos->list() as $video) if ($this->matches($term, $video->title, $video->externalVideoId, $video->canonicalUrl)) $groups['videos'][] = ['type' => 'video', 'id' => $video->canonicalId, 'title' => $video->title, 'platform' => $video->platform, 'url' => $video->canonicalUrl];
        if ($this->ready('knowledge')) foreach ($this->claims->list() as $claim) if ($this->matches($term, $claim->claimText, $claim->stableKey)) $groups['knowledge'][] = ['type' => 'knowledge', 'id' => $claim->canonicalId, 'title' => $claim->claimText, 'stable_key' => $claim->stableKey];
        return ['query' => $term, 'page' => $page, 'per_page' => $perPage, 'post_total' => $postTotal, 'groups' => $groups];
    }

    private function ready(string $domain): bool
    {
        if (!$this->status) return true;
        return match ($domain) { 'authority' => $this->status->authorityStorageReady(), 'media' => $this->status->mediaStorageReady(), 'video' => $this->status->videoStorageReady(), 'knowledge' => $this->status->knowledgeStorageReady(), default => false };
    }
    private function matches(string $term, string ...$values): bool { foreach ($values as $value) if ((function_exists('mb_stripos') ? mb_stripos($value, $term) : stripos($value, $term)) !== false) return true; return false; }
    private function json(array $value): string { return function_exists('wp_json_encode') ? (string) wp_json_encode($value) : (string) json_encode($value); }
    private function entity(AuthorityEntity $entity): array { return ['id' => $entity->canonicalId, 'type' => $entity->entityType, 'stable_key' => $entity->stableKey, 'name' => $entity->canonicalName, 'payload' => $entity->payload, 'active' => $entity->active(), 'revision' => $entity->revision]; }
    private function asset(MediaAsset $asset): array { return ['id' => $asset->assetId, 'kind' => $asset->kind, 'storage_key' => $asset->storageKey, 'checksum' => $asset->checksum, 'mime_type' => $asset->mimeType, 'byte_size' => $asset->byteSize, 'width' => $asset->width, 'height' => $asset->height]; }
    private function usage(MediaUsage $usage): array { return ['id' => $usage->usageId, 'endpoint_type' => $usage->endpointType, 'endpoint_key' => $usage->endpointKey, 'role' => $usage->role, 'sort_order' => $usage->sortOrder]; }
    private function evidence(Evidence $evidence): array { return ['id' => $evidence->canonicalId, 'claim_id' => $evidence->claimId, 'source_id' => $evidence->sourceId, 'relation' => $evidence->relation, 'excerpt' => $evidence->excerpt, 'locator' => $evidence->locator, 'active' => $evidence->active, 'revision' => $evidence->revision]; }
}
