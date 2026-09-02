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
use NHK\Core\Application\Entity\{PublicEntityCollectionQuery, PublicRouteResolver};
use NHK\Core\Application\Video\VideoSearchDocument;
use NHK\Core\Shared\Migration\MigrationStatus;

final class SearchApi
{
    public function __construct(private MediaRepository $media, private VideoRepository $videos, private KnowledgeRepository $claims, private AuthorityRepository $authority, private EntityTypeRegistry $types, private ?MigrationStatus $status = null, private ?PublicEntityCollectionQuery $collection = null) {}

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
        $posts = new \WP_Query(['post_type' => 'post', 'post_status' => 'publish', 's' => $term, 'posts_per_page' => $perPage, 'paged' => $page, 'ignore_sticky_posts' => true]);
        $groups = ['posts' => array_map(static fn (\WP_Post $post): array => ['type' => 'post', 'id' => (string) $post->ID, 'title' => get_the_title($post), 'url' => get_permalink($post), 'excerpt' => wp_trim_words(wp_strip_all_tags(get_the_excerpt($post)), 28), 'date' => get_the_date('c', $post)], $posts->posts)];
        $groups['entities'] = [];
        if (!$this->status || $this->status->authorityStorageReady()) foreach ($this->types->all() as $definition) foreach (($this->collection?->archive($definition->type, 1, 100, $term)['items'] ?? []) as $item) $groups['entities'][] = ['type' => $item['type'], 'title' => $item['name'], 'url' => home_url($item['url'])];
        $groups['media'] = !$this->status || $this->status->mediaStorageReady() ? array_map($this->media(...), array_values(array_filter($this->media->list(), fn (Media $item): bool => $item->active && $item->readiness === 'ready' && $this->matches($term, $item->canonicalName, $item->stableKey)))) : [];
        $videoSearch = new VideoSearchDocument($this->authority);
        $groups['videos'] = !$this->status || $this->status->videoStorageReady() ? array_map($this->video(...), array_values(array_filter($this->videos->list(), fn (Video $item): bool => $item->active && $item->hasValidPublicReference() && $videoSearch->isDiscoverable($item) && $this->matches($term, ...$videoSearch->values($item))))) : [];
        $groups['knowledge'] = !$this->status || $this->status->knowledgeStorageReady() ? array_map($this->claim(...), array_values(array_filter($this->claims->list(), fn (KnowledgeClaim $item): bool => $item->active && $item->isPublic() && $this->matches($term, $item->claimText, $item->stableKey)))) : [];
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
    private function video(Video $item): array { $title = (new VideoSearchDocument($this->authority))->title($item); $path = PublicRouteResolver::videoPath($title, $item->externalVideoId); return ['type' => 'video', 'title' => $title, 'platform' => $item->platform, 'url' => $path === null ? '' : (function_exists('home_url') ? home_url($path) : $path)]; }
    private function claim(KnowledgeClaim $item): array { return ['type' => 'knowledge', 'title' => $item->claimText]; }
}
