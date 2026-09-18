<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Graph\PredicateTraversalPolicy;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Application\Video\{VideoPublicContextSelector, VideoUrlPolicy};
use NHK\Core\Application\Entity\PublicEntityEligibilityPolicy;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Domain\Seo\SeoReadinessResult;
use NHK\Core\Application\Video\{VideoUrlPolicy, VideoPublicContextSelector};
use NHK\Core\Application\Presentation\LatestFirstOrder;

final class RelatedContentQuery
{
    public function __construct(private GraphService $graph, private AuthorityRepository $authority, private MediaRepository $media, private VideoRepository $videos, private EntityTypeRegistry $types, private ?MigrationStatus $status = null, private ?PublicEntityEligibilityPolicy $eligibility = null, ?PredicateTraversalPolicy $policy = null, private ?PublicRouteResolver $routes = null, private ?PublicIdentityRepository $identities = null, private ?VideoUrlPolicy $videoPolicy = null) { $this->policy = $policy ?? new PredicateTraversalPolicy(new \NHK\Core\Domain\Graph\PredicateRegistry()); }
    private PredicateTraversalPolicy $policy;

    /** @return array{entities:list<array<string,mixed>>,articles:list<array<string,mixed>>,media:list<array<string,mixed>>,videos:list<array<string,mixed>>} */
    public function forEntity(string $type, string $id): array
    {
        if ($this->types->has($type) && !UuidCodec::isValid($id)) return $this->emptyGroups();
        return $this->forReference(new NodeReference($type, $id));
    }

    /** @return array{entities:list<array<string,mixed>>,articles:list<array<string,mixed>>,media:list<array<string,mixed>>,videos:list<array<string,mixed>>} */
    public function forPost(int $postId): array
    {
        if ($postId < 1) return $this->emptyGroups();
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        return $this->forReference(new NodeReference('wp_post', $blogId . ':' . $postId));
    }

    /** @return array{entities:list<array<string,mixed>>,articles:list<array<string,mixed>>,media:list<array<string,mixed>>,videos:list<array<string,mixed>>} */
    private function forReference(NodeReference $reference): array
    {
        $groups = $this->emptyGroups();
        if ($this->status && !$this->status->graphStorageReady()) return $groups;
        $seen = [$reference->key() => true];
        $queue = [[$reference, 0]];
        while ($queue !== []) {
            [$current, $depth] = array_shift($queue);
            if ($depth >= 2) continue;
            $pages = [$this->graph->findOutgoing($current, null, 0, 100), $this->graph->findIncoming($current, null, 0, 100)];
            foreach ($pages as $page) foreach ($page['items'] as $edge) {
                $outgoing = $edge->source->reference->key() === $current->key();
                $node = $outgoing ? $edge->target->reference : $edge->source->reference;
                if (!$this->policy->permits($current, $outgoing ? 'outgoing' : 'incoming', $node, $edge->predicate)) continue;
                if (isset($seen[$node->key()])) continue;
                $seen[$node->key()] = true;
                $item = $this->resolve($node);
                // Derived traversal is bounded and only continues through a
                // public, resolvable node; private/unavailable nodes must not
                // become a bridge into public related content.
                if ($item === null) continue;
                $groups[$item['group']][] = $item['value'];
                $queue[] = [$node, $depth + 1];
            }
        }
        foreach ($groups as $group => $items) {
            $ordered = LatestFirstOrder::sort($items, static fn (array $item): ?string => $item['_published_at'] ?? null, static fn (array $item): ?string => $item['_created_at'] ?? null, static fn (array $item): string => (string) ($item['_stable_key'] ?? $item['id'] ?? $item['url'] ?? $item['title'] ?? ''), null, static fn (array $item): ?string => $item['_updated_at'] ?? null);
            $groups[$group] = array_map(static function (array $item): array { unset($item['_published_at'], $item['_created_at'], $item['_updated_at'], $item['_stable_key']); return $item; }, $ordered);
        }
        return $groups;
    }

    /** @return array{entities:list<array<string,mixed>>,articles:list<array<string,mixed>>,media:list<array<string,mixed>>,videos:list<array<string,mixed>>} */
    private function emptyGroups(): array { return ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []]; }

    private function resolve(NodeReference $node): ?array
    {
        if (($this->types->has($node->endpoint_type) || in_array($node->endpoint_type, ['media', 'video'], true)) && !UuidCodec::isValid($node->endpoint_key)) return null;
        if ($this->types->has($node->endpoint_type)) {
            $entity = $this->authority->findByCanonicalId($node->endpoint_key);
            if (!$entity || !$entity->active()) return null;
            if ($this->eligibility !== null && !$this->eligibility->evaluate($entity)->eligible) return null;
            return ['group' => 'entities', 'value' => ['type' => $entity->entityType, 'title' => $entity->canonicalName, 'url' => $this->entityUrl($entity), '_created_at' => $entity->createdAt, '_updated_at' => $entity->updatedAt, '_stable_key' => $entity->canonicalId]];
        }
        if ($node->endpoint_type === 'media') { $media = $this->media->findByCanonicalId($node->endpoint_key); return $media && $media->active && $media->readiness === 'ready' ? ['group' => 'media', 'value' => $this->mediaValue($media)] : null; }
        if ($node->endpoint_type === 'video') { $video = $this->videos->findByCanonicalId($node->endpoint_key); return $video && $video->active && $video->hasValidPublicReference() ? ['group' => 'videos', 'value' => $this->videoValue($video)] : null; }
        if ($node->endpoint_type === 'wp_post' && preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $node->endpoint_key, $match) === 1 && function_exists('get_post')) {
            $post = get_post((int) $match[1]);
            if ($post instanceof \WP_Post && get_post_status($post) === 'publish') return ['group' => 'articles', 'value' => ['type' => 'post', 'id' => (string) $post->ID, 'title' => get_the_title($post), 'url' => get_permalink($post), '_published_at' => $post->post_date_gmt ?: $post->post_date, '_created_at' => $post->post_date_gmt ?: $post->post_date, '_updated_at' => $post->post_modified_gmt ?: $post->post_modified, '_stable_key' => (string) $post->ID]];
        }
        return null;
    }
    private function mediaValue(Media $media): array { return ['type' => 'media', 'title' => $media->canonicalName, 'url' => '', '_created_at' => $media->createdAt, '_updated_at' => $media->updatedAt, '_stable_key' => $media->canonicalId]; }
    private function videoValue(Video $video): array { $metadata = is_array($video->metadata) ? $video->metadata : []; $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : (is_array($metadata['source'] ?? null) ? $metadata['source'] : []); $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : []; $title = trim((string) ($editorial['title'] ?? '')) ?: $video->title; $url = (new PublicSeoProjection())->project((new VideoUrlPolicy())->project($video, new VideoPublicContextSelector()), ['type' => 'VideoObject'])['internal_link']; return ['type' => 'video', 'title' => $title, 'url' => $url ?? '', 'source_url' => $video->canonicalUrl, '_published_at' => $source['published_at'] ?? null, '_created_at' => $video->createdAt, '_updated_at' => $video->updatedAt, '_stable_key' => $video->canonicalId]; }
    private function entityUrl(AuthorityEntity $entity): string { $path = (new PublicRouteResolver($this->authority, $this->types))->path($entity); return $path === null ? '' : (new PublicSeoProjection())->project(['path' => $path, 'eligible' => true, 'readiness' => SeoReadinessResult::READY, 'canonical_url' => $path, 'public_eligible' => true], ['type' => 'Entity'])['internal_link'] ?? ''; }
}
