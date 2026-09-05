<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Graph\{GraphService, PredicateTraversalPolicy};
use NHK\Core\Application\Media\PublicMediaGalleryQuery;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Application\Video\{VideoPublicContextSelector, VideoUrlPolicy};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Seo\SeoReadinessResult;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Shared\Uuid\UuidCodec;

final class RelatedContentQuery
{
    private PredicateTraversalPolicy $policy;

    public function __construct(
        private GraphService $graph,
        private AuthorityRepository $authority,
        private MediaRepository $media,
        private VideoRepository $videos,
        private EntityTypeRegistry $types,
        private ?MigrationStatus $status = null,
        private ?PublicEntityEligibilityPolicy $eligibility = null,
        ?PredicateTraversalPolicy $policy = null,
        private ?PublicMediaGalleryQuery $mediaGallery = null,
    ) { $this->policy = $policy ?? new PredicateTraversalPolicy(new \NHK\Core\Domain\Graph\PredicateRegistry()); }

    public function forEntity(string $type, string $id): array
    {
        if ($this->types->has($type) && !UuidCodec::isValid($id)) return $this->emptyGroups();
        return $this->forReference(new NodeReference($type, $id));
    }

    public function forPost(int $postId): array
    {
        if ($postId < 1) return $this->emptyGroups();
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        return $this->forReference(new NodeReference('wp_post', $blogId . ':' . $postId));
    }

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
                if ($item === null) continue;
                $item['value']['relationship_class'] = $depth === 0 ? 'DIRECT' : 'DERIVED';
                $item['value']['hop_count'] = $depth + 1;
                $groups[$item['group']][] = $item['value'];
                $queue[] = [$node, $depth + 1];
            }
        }
        return $groups;
    }

    private function emptyGroups(): array { return ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []]; }

    private function resolve(NodeReference $node): ?array
    {
        if (($this->types->has($node->endpoint_type) || in_array($node->endpoint_type, ['media', 'video'], true)) && !UuidCodec::isValid($node->endpoint_key)) return null;
        if ($this->types->has($node->endpoint_type)) {
            $entity = $this->authority->findByCanonicalId($node->endpoint_key);
            if (!$entity || !$entity->active()) return null;
            if ($this->eligibility !== null && !$this->eligibility->evaluate($entity)->eligible) return null;
            $url = $this->entityUrl($entity);
            return $url === '' ? null : ['group' => 'entities', 'value' => ['type' => $entity->entityType, 'title' => $entity->canonicalName, 'url' => $url]];
        }
        if ($node->endpoint_type === 'media') {
            $media = $this->media->findByCanonicalId($node->endpoint_key);
            return $media && $media->active && $media->readiness === 'ready' && !$media->isSystemPlaceholder() ? ['group' => 'media', 'value' => $this->mediaValue($media)] : null;
        }
        if ($node->endpoint_type === 'video') {
            $video = $this->videos->findByCanonicalId($node->endpoint_key);
            return $video && $video->active && $video->hasValidPublicReference() ? ['group' => 'videos', 'value' => $this->videoValue($video)] : null;
        }
        if ($node->endpoint_type === 'wp_post' && preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $node->endpoint_key, $match) === 1 && function_exists('get_post')) {
            $post = get_post((int) $match[1]);
            if ($post instanceof \WP_Post && get_post_status($post) === 'publish') return ['group' => 'articles', 'value' => ['type' => 'post', 'id' => (string) $post->ID, 'title' => get_the_title($post), 'url' => get_permalink($post)]];
        }
        return null;
    }

    private function mediaValue(Media $media): array
    {
        $value = ['type' => 'media', 'title' => $media->canonicalName, 'url' => ''];
        $visual = $this->mediaGallery?->forMedia($media->canonicalId);
        if (is_array($visual)) {
            $value['image_url'] = $visual['image_url'] ?? null;
            $value['alt'] = $visual['alt'] ?? $media->canonicalName;
            $value['width'] = $visual['width'] ?? null;
            $value['height'] = $visual['height'] ?? null;
        }
        return $value;
    }

    private function videoValue(Video $video): array
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
        $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : [];
        $title = trim((string) ($editorial['title'] ?? '')) ?: $video->title;
        $url = (new PublicSeoProjection())->project((new VideoUrlPolicy())->project($video, new VideoPublicContextSelector()), ['type' => 'VideoObject'])['internal_link'];
        $thumbnail = is_array($source['thumbnail_urls'] ?? null) ? (string) ($source['thumbnail_urls'][0] ?? '') : '';
        if ($thumbnail === '' || filter_var($thumbnail, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($thumbnail, PHP_URL_SCHEME)) !== 'https') $thumbnail = '';
        return ['type' => 'video', 'title' => $title, 'url' => $url ?? '', 'source_url' => $video->canonicalUrl, 'thumbnail_url' => $thumbnail !== '' ? $thumbnail : null];
    }

    private function entityUrl(AuthorityEntity $entity): string
    {
        $path = (new PublicRouteResolver($this->authority, $this->types))->path($entity);
        return $path === null ? '' : (new PublicSeoProjection())->project(['path' => $path, 'eligible' => true, 'readiness' => SeoReadinessResult::READY, 'canonical_url' => $path, 'public_eligible' => true], ['type' => 'Entity'])['internal_link'] ?? '';
    }
}
