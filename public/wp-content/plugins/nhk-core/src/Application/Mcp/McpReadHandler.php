<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Media\PublicMediaAssetDelivery;
use NHK\Core\Application\Video\VideoSearchDocument;
use NHK\Core\Application\Graph\SemanticNeighborhoodQuery;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Contracts\Media\WordPressMediaAttachmentIngestor;
use NHK\Core\Application\Inventory\{CanonicalInventoryService, GraphInventoryService};
use NHK\Core\Application\Graph\RelationBackfillService;

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
        private ?SourceRepository $sources = null,
        private ?PublicMediaAssetDelivery $delivery = null,
        private ?McpSemanticContextResolver $resolver = null,
        private ?WordPressMediaAttachmentIngestor $wordpressAttachments = null,
        private ?SemanticNeighborhoodQuery $neighborhood = null,
        private ?CanonicalInventoryService $canonicalInventory = null,
        private ?GraphInventoryService $graphInventory = null,
        private ?RelationBackfillService $relationBackfill = null,
    ) { $this->delivery ??= PublicMediaAssetDelivery::fromEnvironment($assets, $media); }

    public function entityGet(string $type, string $id): ?array
    {
        if (!$this->types->has($type) || !$this->ready('authority') || !UuidCodec::isValid($id)) return null;
        $entity = $this->authority->findByCanonicalId($id);
        return $entity && $entity->entityType === $type && $entity->active() ? $this->entity($entity) : null;
    }

    public function mediaGet(string $id): ?array
    {
        if (!$this->ready('media') || !UuidCodec::isValid($id)) return null;
        $media = $this->media->findByCanonicalId($id);
        if (!$media || !$media->active || $media->readiness !== 'ready') return null;
        $assets = array_values(array_filter($this->assets->listByMediaId($id), fn (MediaAsset $asset): bool => $asset->visibility === 'PUBLIC' && ($this->delivery === null || $this->delivery->resolve($asset->assetId) !== null)));
        return ['id' => $media->canonicalId, 'stable_key' => $media->stableKey, 'name' => $media->canonicalName, 'assets' => array_map($this->publicAsset(...), $assets), 'usages' => array_map($this->publicUsage(...), $this->usages->listByMediaId($id))];
    }

    public function mediaAttachmentGet(int $attachmentId): ?array
    {
        return $this->wordpressAttachments?->read($attachmentId);
    }

    public function videoGet(string $id): ?array
    {
        if (!$this->ready('video') || !UuidCodec::isValid($id)) return null;
        $video = $this->videos->findByCanonicalId($id);
        return $video && $video->active && $video->hasValidPublicReference() ? ['id' => $video->canonicalId, 'platform' => $video->platform, 'external_id' => $video->externalVideoId, 'url' => $video->canonicalUrl, 'title' => $video->title] : null;
    }

    public function knowledgeGet(string $id): ?array
    {
        if (!$this->ready('knowledge') || !UuidCodec::isValid($id)) return null;
        $claim = $this->claims->findByCanonicalId($id);
        return $claim && $claim->active && $claim->isPublic() ? ['id' => $claim->canonicalId, 'stable_key' => $claim->stableKey, 'text' => $claim->claimText, 'type' => $claim->claimType, 'evidence' => array_map($this->publicEvidence(...), $this->publicEvidenceByClaim($id))] : null;
    }

    public function sourceGet(string $id): ?array
    {
        if (!$this->ready('knowledge') || $this->sources === null || !UuidCodec::isValid($id)) return null;
        $source = $this->sources->findByCanonicalId($id);
        if (!$source || !$source->active || !$source->isPublic()) return null;
        $evidence = array_values(array_filter($this->evidence->listBySource($id), function (Evidence $item): bool {
            if (!$item->active || !$item->isPublic()) return false;
            $claim = $this->claims->findByCanonicalId($item->claimId);
            return $claim !== null && $claim->active && $claim->isPublic();
        }));
        return ['id' => $source->canonicalId, 'stable_key' => $source->stableKey, 'title' => $source->title, 'type' => $source->sourceType, 'locator' => $source->locator, 'evidence' => array_map($this->publicEvidence(...), $evidence)];
    }

    public function evidenceGet(string $id): ?array
    {
        if (!$this->ready('knowledge') || $this->sources === null || !UuidCodec::isValid($id)) return null;
        $item = $this->evidence->findByCanonicalId($id);
        if (!$item || !$item->active || !$item->isPublic()) return null;
        $claim = $this->claims->findByCanonicalId($item->claimId);
        $source = $this->sources->findByCanonicalId($item->sourceId);
        if (!$claim || !$claim->active || !$claim->isPublic() || !$source || !$source->active || !$source->isPublic()) return null;
        return $this->publicEvidence($item) + ['source_title' => $source->title, 'source_type' => $source->sourceType, 'source_locator' => $source->locator];
    }

    public function semanticResolve(array $context): array
    {
        if ($this->resolver === null) throw new \InvalidArgumentException('Semantic context resolver is unavailable.');
        return $this->resolver->resolve($context);
    }

    public function canonicalInventory(array $filters, int $limit = 50, ?string $after = null): array
    {
        if ($this->canonicalInventory === null) return ['status' => 'unavailable', 'reason' => 'CANONICAL_INVENTORY_UNAVAILABLE'];
        return ['status' => 'available'] + $this->canonicalInventory->inventory($filters, $limit, $after)->toArray();
    }

    public function graphInventory(array $filters, int $limit = 50, ?string $after = null): array
    {
        if ($this->graphInventory === null) return ['status' => 'unavailable', 'reason' => 'GRAPH_INVENTORY_UNAVAILABLE'];
        return ['status' => 'available'] + $this->graphInventory->inventory($filters, $limit, $after)->toArray();
    }

    public function relationBackfillDryRun(array $records): array
    {
        if ($this->relationBackfill === null) return ['status' => 'unavailable', 'reason' => 'RELATION_DRY_RUN_UNAVAILABLE'];
        return ['status' => 'available', 'read_only' => true] + $this->relationBackfill->dryRun($records)->toArray();
    }

    /** @return array{status:string,items:list<array<string,mixed>>,reason?:string} */
    public function entityNeighborhood(string $type, string $id, string $profile, int $maxHops = 2, int $limit = 50): array
    {
        if ($this->neighborhood === null || !UuidCodec::isValid($id)) return ['status' => 'unavailable', 'items' => [], 'reason' => 'GRAPH_RESEARCH_UNAVAILABLE'];
        return $this->neighborhood->query(new NodeReference($type, $id), $profile, $maxHops, $limit);
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
        if ($this->ready('authority')) foreach ($this->types->all() as $definition) foreach ($this->authority->listByType($definition->type) as $entity) { $publicPayload = array_intersect_key($entity->payload, array_fill_keys($definition->allowedFields, true)); if ($entity->active() && $this->matches($term, $entity->canonicalName, $entity->stableKey, $this->json($publicPayload))) $groups['entities'][] = ['type' => $entity->entityType, 'id' => $entity->canonicalId, 'title' => $entity->canonicalName, 'stable_key' => $entity->stableKey]; }
        if ($this->ready('media')) foreach ($this->media->list() as $media) if ($media->active && $media->readiness === 'ready' && $this->matches($term, $media->canonicalName, $media->stableKey)) $groups['media'][] = ['type' => 'media', 'id' => $media->canonicalId, 'title' => $media->canonicalName, 'stable_key' => $media->stableKey];
        if ($this->ready('video')) { $videoSearch = new VideoSearchDocument($this->authority); foreach ($this->videos->list() as $video) if ($videoSearch->isDiscoverable($video) && $this->matches($term, ...$videoSearch->values($video))) $groups['videos'][] = ['type' => 'video', 'id' => $video->canonicalId, 'title' => $videoSearch->title($video), 'platform' => $video->platform, 'url' => $video->canonicalUrl]; }
        if ($this->ready('knowledge')) foreach ($this->claims->list() as $claim) if ($claim->active && $claim->isPublic() && $this->matches($term, $claim->claimText, $claim->stableKey)) $groups['knowledge'][] = ['type' => 'knowledge', 'id' => $claim->canonicalId, 'title' => $claim->claimText, 'stable_key' => $claim->stableKey];
        $semanticTotals = [];
        $semanticOffset = ($page - 1) * $perPage;
        foreach (['entities', 'media', 'videos', 'knowledge'] as $group) {
            $semanticTotals[$group] = count($groups[$group]);
            $groups[$group] = array_slice($groups[$group], $semanticOffset, $perPage);
        }
        return ['query' => $term, 'page' => $page, 'per_page' => $perPage, 'post_total' => $postTotal, 'semantic_totals' => $semanticTotals, 'groups' => $groups];
    }

    private function ready(string $domain): bool
    {
        if (!$this->status) return true;
        return match ($domain) { 'authority' => $this->status->authorityStorageReady(), 'media' => $this->status->mediaStorageReady(), 'video' => $this->status->videoStorageReady(), 'knowledge' => $this->status->knowledgeStorageReady(), default => false };
    }
    private function matches(string $term, string ...$values): bool { foreach ($values as $value) if ((function_exists('mb_stripos') ? mb_stripos($value, $term) : stripos($value, $term)) !== false) return true; return false; }
    private function json(array $value): string { return function_exists('wp_json_encode') ? (string) wp_json_encode($value) : (string) json_encode($value); }
    private function entity(AuthorityEntity $entity): array { $definition = $this->types->get($entity->entityType); $payload = array_intersect_key($entity->payload, array_fill_keys($definition->allowedFields, true)); return ['id' => $entity->canonicalId, 'type' => $entity->entityType, 'stable_key' => $entity->stableKey, 'name' => $entity->canonicalName, 'payload' => $payload]; }
    private function asset(MediaAsset $asset): array { return ['id' => $asset->assetId, 'kind' => $asset->kind, 'storage_key' => $asset->storageKey, 'checksum' => $asset->checksum, 'mime_type' => $asset->mimeType, 'byte_size' => $asset->byteSize, 'width' => $asset->width, 'height' => $asset->height, 'visibility' => $asset->visibility, 'metadata' => $asset->metadata]; }
    private function usage(MediaUsage $usage): array { return ['id' => $usage->usageId, 'endpoint_type' => $usage->endpointType, 'endpoint_key' => $usage->endpointKey, 'role' => $usage->role, 'sort_order' => $usage->sortOrder, 'alt' => $usage->altText, 'caption' => $usage->caption, 'keyword_groups' => $usage->keywordGroups]; }
    private function publicAsset(MediaAsset $asset): array { return ['id' => $asset->assetId, 'kind' => $asset->kind, 'mime_type' => $asset->mimeType, 'byte_size' => $asset->byteSize, 'width' => $asset->width, 'height' => $asset->height, 'public_url' => '/media/asset/' . $asset->assetId . '/']; }
    private function publicUsage(MediaUsage $usage): array { return ['id' => $usage->usageId, 'role' => $usage->role, 'sort_order' => $usage->sortOrder]; }
    private function evidence(Evidence $evidence): array { return ['id' => $evidence->canonicalId, 'claim_id' => $evidence->claimId, 'source_id' => $evidence->sourceId, 'relation' => $evidence->relation, 'excerpt' => $evidence->excerpt, 'locator' => $evidence->locator, 'metadata' => $evidence->metadata, 'active' => $evidence->active, 'revision' => $evidence->revision]; }
    private function publicEvidence(Evidence $evidence): array { return ['id' => $evidence->canonicalId, 'claim_id' => $evidence->claimId, 'source_id' => $evidence->sourceId, 'relation' => $evidence->relation, 'excerpt' => $evidence->excerpt, 'locator' => $evidence->locator]; }
    private function publicEvidenceByClaim(string $claimId): array { return array_values(array_filter($this->evidence->listByClaim($claimId), function (Evidence $item): bool { if (!$item->active || !$item->isPublic() || $this->sources === null) return false; $source = $this->sources->findByCanonicalId($item->sourceId); $claim = $this->claims->findByCanonicalId($item->claimId); return $source !== null && $source->active && $source->isPublic() && $claim !== null && $claim->active && $claim->isPublic(); })); }
}
