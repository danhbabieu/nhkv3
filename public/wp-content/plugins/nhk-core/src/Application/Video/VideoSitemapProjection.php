<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Domain\Video\Video;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Application\Seo\SitemapIndexabilityProjection;

final class VideoSitemapProjection
{
    public function __construct(private ?PublicIdentityRepository $identities = null, private ?VideoUrlPolicy $policy = null)
    {
    }
    /** @param list<Video> $videos @return list<array<string,string>> */
    public function project(array $videos, string $baseUrl = ''): array
    {
        $items = [];
        $seo = new PublicSeoProjection();
        $policy = new VideoUrlPolicy();
        $selector = new VideoPublicContextSelector();
        $sitemap = new SitemapIndexabilityProjection();
        foreach ($videos as $video) {
            if (!$video->active) continue;
            $source = is_array($video->metadata['source_snapshot'] ?? null)
                ? $video->metadata['source_snapshot']
                : (is_array($video->metadata['source'] ?? null) ? $video->metadata['source'] : []);
            if (($source['availability'] ?? 'unknown') !== 'available') continue;
            if (($video->metadata['indexable'] ?? true) !== true) continue;
            $url = $policy->project($video, $selector);
            if (!$url['eligible'] || $url['path'] === null) continue;
            $path = $seo->project($url, ['type' => 'VideoObject'])['sitemap'];
            $decision = $sitemap->include([
                'canonical_url' => $path,
                'rendered_url' => $path,
                'readiness' => 'READY',
                'public_eligible' => true,
                'indexable' => true,
            ]);
            if (!$decision['included']) continue;
            $loc = $baseUrl !== '' ? rtrim($baseUrl, '/') . $path : $path;
            $item = ['loc' => $loc, 'title' => (string) ($video->metadata['editorial']['title'] ?? $video->title), 'description' => (string) ($video->metadata['editorial']['summary'] ?? '')];
            $thumbnail = (new VideoThumbnailSelector())->fromSource($source);
            $thumbnail = (string) ($thumbnail['url'] ?? '');
            if (strtolower((string) parse_url($thumbnail, PHP_URL_SCHEME)) !== 'https') continue;
            $item['thumbnail_url'] = $thumbnail;
            $items[] = $item;
        }
        return $items;
    }
}
