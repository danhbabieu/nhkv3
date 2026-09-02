<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Application\Entity\PublicEntityEligibilityPolicy;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Shared\Uuid\UuidCodec;

final class RelatedContentQuery
{
    public function __construct(private GraphService $graph, private AuthorityRepository $authority, private MediaRepository $media, private VideoRepository $videos, private EntityTypeRegistry $types, private ?MigrationStatus $status = null, private ?PublicEntityEligibilityPolicy $eligibility = null) {}

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
        $seen = [];
        $pages = [$this->graph->findOutgoing($reference, null, 0, 100), $this->graph->findIncoming($reference, null, 0, 100)];
        foreach ($pages as $page) foreach ($page['items'] as $edge) {
            $node = $edge->source->reference->key() === $reference->key() ? $edge->target->reference : $edge->source->reference;
            if ($node->key() === $reference->key() || isset($seen[$node->key()])) continue;
            $seen[$node->key()] = true; $item = $this->resolve($node);
            if ($item === null) continue;
            $groups[$item['group']][] = $item['value'];
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
            return ['group' => 'entities', 'value' => ['type' => $entity->entityType, 'title' => $entity->canonicalName, 'url' => $this->entityUrl($entity)]];
        }
        if ($node->endpoint_type === 'media') { $media = $this->media->findByCanonicalId($node->endpoint_key); return $media && $media->active && $media->readiness === 'ready' ? ['group' => 'media', 'value' => $this->mediaValue($media)] : null; }
        if ($node->endpoint_type === 'video') { $video = $this->videos->findByCanonicalId($node->endpoint_key); return $video && $video->active && $video->hasValidPublicReference() ? ['group' => 'videos', 'value' => $this->videoValue($video)] : null; }
        if ($node->endpoint_type === 'wp_post' && preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $node->endpoint_key, $match) === 1 && function_exists('get_post')) {
            $post = get_post((int) $match[1]);
            if ($post instanceof \WP_Post && get_post_status($post) === 'publish') return ['group' => 'articles', 'value' => ['type' => 'post', 'id' => (string) $post->ID, 'title' => get_the_title($post), 'url' => get_permalink($post)]];
        }
        return null;
    }
    private function mediaValue(Media $media): array { $path = PublicRouteResolver::existingSemanticPath('media', $media->canonicalId); return ['type' => 'media', 'title' => $media->canonicalName, 'url' => $path === null ? '' : (function_exists('home_url') ? home_url($path) : $path)]; }
    private function videoValue(Video $video): array { $path = PublicRouteResolver::videoPath($video->title, $video->externalVideoId); return ['type' => 'video', 'title' => $video->title, 'url' => $path === null ? '' : (function_exists('home_url') ? home_url($path) : $path), 'source_url' => $video->canonicalUrl]; }
    private function entityUrl(AuthorityEntity $entity): string { $path = (new PublicRouteResolver($this->authority, $this->types))->path($entity); return $path === null ? '' : (function_exists('home_url') ? home_url($path) : $path); }
}
