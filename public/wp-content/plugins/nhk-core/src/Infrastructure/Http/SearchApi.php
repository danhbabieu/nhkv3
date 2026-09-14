<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Application\Entity\{EntityProfileRegistry, PublicEntityCollectionQuery, PublicRouteResolver};
use NHK\Core\Application\Video\VideoSearchDocument;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Application\Presentation\LatestFirstOrder;
use NHK\Core\Shared\Migration\MigrationStatus;

final class SearchApi
{
    public function __construct(private MediaRepository $media, private VideoRepository $videos, private KnowledgeRepository $claims, private AuthorityRepository $authority, private EntityTypeRegistry $types, private ?MigrationStatus $status = null, private ?PublicEntityCollectionQuery $collection = null, private $claimOwnerUrl = null) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/search', ['methods' => 'GET', 'permission_callback' => '__return_true', 'args' => ['q' => ['required' => true], 'page' => ['default' => 1], 'per_page' => ['default' => 20]], 'callback' => fn (\WP_REST_Request $request) => $this->search($request)]);
    }

    private function search(\WP_REST_Request $request): array|\WP_Error
    {
        $term = trim((string) $request['q']);
        $length = function_exists('mb_strlen') ? mb_strlen($term) : strlen($term);
        if ($length < 2 || $length > 120) return new \WP_Error('nhk_search_term_invalid', 'Search term must contain 2–120 characters.', ['status' => 400]);
        $page = max(1, (int) $request['page']); $perPage = min(50, max(1, (int) $request['per_page']));
        $posts = new \WP_Query(['post_type' => 'post', 'post_status' => 'publish', 's' => $term, 'posts_per_page' => $perPage, 'paged' => $page, 'ignore_sticky_posts' => true, 'orderby' => ['date' => 'DESC', 'ID' => 'DESC']]);
        $groups = ['posts' => array_map(static fn (\WP_Post $post): array => ['type' => 'post', 'id' => (string) $post->ID, 'title' => get_the_title($post), 'url' => get_permalink($post), 'excerpt' => wp_trim_words(wp_strip_all_tags(get_the_excerpt($post)), 28), 'date' => get_the_date('c', $post)], $posts->posts)];
        $groups['entities'] = [];
        if (!$this->status || $this->status->authorityStorageReady()) foreach ($this->entityItemsForSearch($term) as $item) $groups['entities'][] = $item;
        $groups['media'] = !$this->status || $this->status->mediaStorageReady() ? array_map($this->media(...), array_values(array_filter(LatestFirstOrder::sort($this->media->list(), static fn (Media $item): ?string => null, static fn (Media $item): ?string => $item->createdAt, static fn (Media $item): string => $item->canonicalId), fn (Media $item): bool => $item->active && $item->readiness === 'ready' && $this->matches($term, $item->canonicalName, $item->stableKey)))) : [];
        $videoSearch = new VideoSearchDocument($this->authority);
        $videos = LatestFirstOrder::sort($this->videos->list(), fn (Video $item): ?string => $this->videoPublishedAt($item), fn (Video $item): ?string => $item->createdAt, fn (Video $item): string => $item->canonicalId);
        $groups['videos'] = !$this->status || $this->status->videoStorageReady() ? array_map($this->video(...), array_values(array_filter($videos, fn (Video $item): bool => $item->active && $item->hasValidPublicReference() && $videoSearch->isDiscoverable($item) && $this->matches($term, ...$videoSearch->values($item))))) : [];
        $groups['knowledge'] = [];
        if (!$this->status || $this->status->knowledgeStorageReady()) {
            $owners = [];
            foreach (LatestFirstOrder::sort($this->claims->list(), static fn (KnowledgeClaim $item): ?string => null, static fn (KnowledgeClaim $item): ?string => $item->createdAt, static fn (KnowledgeClaim $item): string => $item->canonicalId) as $item) {
                if (!$item->active || !$item->isPublic() || !$this->matches($term, $item->claimText, $item->stableKey)) continue;
                $claim = $this->claim($item);
                if ($claim === null || isset($owners[$claim['url']])) continue;
                $owners[$claim['url']] = true;
                $groups['knowledge'][] = $claim;
            }
        }
        $semanticTotals = [];
        $semanticOffset = ($page - 1) * $perPage;
        foreach (['entities', 'media', 'videos', 'knowledge'] as $group) {
            $semanticTotals[$group] = count($groups[$group]);
            $groups[$group] = array_slice($groups[$group], $semanticOffset, $perPage);
        }
        return ['query' => $term, 'page' => $page, 'per_page' => $perPage, 'post_total' => (int) $posts->found_posts, 'semantic_totals' => $semanticTotals, 'groups' => $groups];
    }

    private function matches(string $term, string ...$values): bool { foreach ($values as $value) if ((function_exists('mb_stripos') ? mb_stripos($value, $term) : stripos($value, $term)) !== false) return true; return false; }
    private function media(Media $item): array { return ['type' => 'media', 'title' => $item->canonicalName]; }
    private function video(Video $item): array { $search = new VideoSearchDocument($this->authority); $title = $search->title($item); $path = $search->publicUrl($item); $url = $path === null ? null : (new PublicSeoProjection())->project(['path' => $path, 'eligible' => true], ['type' => 'VideoObject'])['search']; return ['type' => 'video', 'title' => $title, 'platform' => $item->platform, 'url' => $url === null ? '' : (function_exists('home_url') ? home_url($url) : $url)]; }
    private function videoPublishedAt(Video $video): ?string { $metadata = is_array($video->metadata) ? $video->metadata : []; $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : (is_array($metadata['source'] ?? null) ? $metadata['source'] : []); return isset($source['published_at']) && is_string($source['published_at']) ? $source['published_at'] : null; }
    private function claim(KnowledgeClaim $item): ?array
    {
        $url = is_callable($this->claimOwnerUrl) ? ($this->claimOwnerUrl)($item) : null;
        return is_string($url) && trim($url) !== '' ? ['type' => 'knowledge', 'title' => $item->claimText, 'url' => $url] : null;
    }

    /** @return list<array<string,mixed>> */
    private function entityItemsForSearch(string $term): array
    {
        if ($this->collection === null) return [];
        $routes = new PublicRouteResolver($this->authority, $this->types);
        $profileOnly = [];
        $items = [];
        foreach ((new EntityProfileRegistry())->all() as $profile) {
            $type = (string) ($profile->matchingRule['entity_type'] ?? '');
            $profilePath = $routes->archivePathForProfile($profile->key);
            $genericPath = $type === '' ? null : $routes->archivePath($type);
            if ($type === '' || $profilePath === null || $profilePath === $genericPath) continue;
            $profileOnly[$profile->key] = true;
            foreach ($this->collection->archiveProfile($profile->key, 1, 100, $term)['items'] as $item) $items[] = $this->searchEntity($item);
        }
        foreach ($this->types->all() as $definition) foreach (($this->collection->archive($definition->type, 1, 100, $term)['items'] ?? []) as $item) {
            if (isset($profileOnly[(string) ($item['profile_key'] ?? '')])) continue;
            $items[] = $this->searchEntity($item);
        }
        return $items;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function searchEntity(array $item): array
    {
        return ['type' => $item['type'], 'profile_key' => $item['profile_key'] ?? null, 'profile_label' => $item['profile_label'] ?? null, 'profile_badge' => $item['profile_badge'] ?? null, 'profile_status' => $item['profile_status'] ?? null, 'title' => $item['name'], 'url' => (new PublicSeoProjection())->project(['path' => $item['url'], 'eligible' => true, 'canonical_url' => $item['url'], 'readiness' => 'READY', 'public_eligible' => true], ['type' => 'Entity'])['search']];
    }
}
