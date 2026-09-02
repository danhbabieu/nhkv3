<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Domain\Video\Video;

final class VideoSitemapProjection
{
    /** @param list<Video> $videos @return list<array<string,string>> */
    public function project(array $videos, string $baseUrl = ''): array
    {
        $items = [];
        foreach ($videos as $video) {
            if (!$video->active || !$video->hasValidPublicReference()) continue;
            $source = is_array($video->metadata['source_snapshot'] ?? null) ? $video->metadata['source_snapshot'] : [];
            if (($source['availability'] ?? 'unknown') !== 'available') continue;
            if (($video->metadata['indexable'] ?? true) !== true) continue;
            $path = PublicRouteResolver::videoPath((string) ($video->metadata['editorial']['title'] ?? $video->title), $video->externalVideoId);
            if ($path === null) continue;
            $loc = $baseUrl !== '' ? rtrim($baseUrl, '/') . $path : $path;
            $item = ['loc' => $loc, 'title' => (string) ($video->metadata['editorial']['title'] ?? $video->title), 'description' => (string) ($video->metadata['editorial']['summary'] ?? '')];
            $thumbnail = is_array($source['thumbnail_urls'] ?? null) ? (string) ($source['thumbnail_urls'][0] ?? '') : '';
            if (strtolower((string) parse_url($thumbnail, PHP_URL_SCHEME)) !== 'https') continue;
            $item['thumbnail_url'] = $thumbnail;
            $items[] = $item;
        }
        return $items;
    }
}
