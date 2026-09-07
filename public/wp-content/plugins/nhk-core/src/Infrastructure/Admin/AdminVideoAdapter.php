<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Domain\Video\Video;

final class AdminVideoAdapter
{
    /** @param iterable<Video> $videos */
    public function __construct(private iterable $videos) {}

    /** @return list<array<string,mixed>> */
    public function find(string $query = ''): array
    {
        $query = strtolower(trim($query)); $rows = [];
        foreach ($this->videos as $video) {
            if (!$video instanceof Video) continue;
            $haystack = strtolower(implode(' ', [$video->title, $video->externalVideoId, $video->canonicalId, $video->platform]));
            if ($query !== '' && !str_contains($haystack, $query)) continue;
            $rows[] = ['id' => $video->canonicalId, 'title' => $video->title !== '' ? $video->title : 'Video chưa có tiêu đề', 'platform' => $video->platform, 'external_id' => $video->externalVideoId, 'active' => $video->active, 'revision' => $video->revision, 'thumbnail_media_id' => $video->thumbnailMediaId, 'url' => $video->canonicalUrl];
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $relations @param list<array<string,mixed>> $evidence */
    public function detail(Video $video, array $relations, array $evidence, array $governance, array $frontendProjection): array
    {
        return [
            'video' => $this->find($video->externalVideoId)[0] ?? [],
            'metadata' => $video->metadata,
            'relations' => array_values($relations),
            'evidence' => array_values($evidence),
            'governance' => $governance,
            'frontend_projection' => $frontendProjection,
        ];
    }
}
