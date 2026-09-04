<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Application\Media\ArticleMediaSeoProjection;
use NHK\Core\Application\Seo\SitemapIndexabilityProjection;

/** Projection-only image sitemap backed by the canonical Article media read. */
final class WordPressImageSitemapProvider extends \WP_Sitemaps_Provider
{
    protected $name = 'images';
    protected $object_type = 'media';

    public function __construct(private ArticleMediaSeoProjection $projection, private int $perPage = 2000) {}

    public function get_url_list($page_num, $object_subtype = ''): array
    {
        $page = max(1, (int) $page_num);
        $ids = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => $this->perPage, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true]);
        $items = [];
        $sitemap = new SitemapIndexabilityProjection();
        foreach (is_array($ids) ? $ids : [] as $postId) {
            $seo = $this->projection->forPost((string) get_current_blog_id() . ':' . (int) $postId);
            $url = trim((string) ($seo['image_url'] ?? ''));
            $decision = $sitemap->include(['canonical_url' => $url, 'rendered_url' => $url, 'readiness' => ($seo['eligible'] ?? false) === true ? 'READY' : 'INCOMPLETE', 'public_eligible' => true, 'indexable' => ($seo['eligible'] ?? false) === true]);
            if ($decision['included']) $items[$url] = ['loc' => $url];
        }
        return array_values($items);
    }

    public function get_max_num_pages($object_subtype = ''): int
    {
        $counts = wp_count_posts('post');
        $published = is_object($counts) ? (int) ($counts->publish ?? 0) : 0;
        return max(1, (int) ceil($published / $this->perPage));
    }
}
