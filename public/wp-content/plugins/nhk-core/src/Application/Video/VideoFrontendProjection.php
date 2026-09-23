<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Domain\Video\Video;

/**
 * The read-only Video projection consumed by public frontend surfaces.
 *
 * This is deliberately derived from the canonical Video and persisted Public
 * Identity. It is not a second owner or a WordPress post projection.
 */
final class VideoFrontendProjection
{
    /** @return array{item:?array<string,mixed>,frontend_available:bool,public_eligible:bool,blockers:list<string>} */
    public function project(Video $video): array
    {
        $url = (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector());
        $blockers = array_values(array_unique(array_map('strval', (array) ($url['blockers'] ?? []))));
        if (($url['eligible'] ?? false) !== true || !is_string($url['path'] ?? null) || trim((string) $url['path']) === '') {
            return ['item' => null, 'frontend_available' => false, 'public_eligible' => false, 'blockers' => $blockers ?: ['VIDEO_FRONTEND_PROJECTION_UNAVAILABLE']];
        }

        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $source = is_array($metadata['source_snapshot'] ?? null)
            ? $metadata['source_snapshot']
            : (is_array($metadata['source'] ?? null) ? $metadata['source'] : []);
        $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
        $title = trim((string) ($editorial['title'] ?? '')) ?: ($video->title ?: 'Video');
        $thumbnail = (new VideoThumbnailSelector())->presentationFromSource($source);

        return [
            'item' => [
                'canonical_id' => $video->canonicalId,
                'public_url' => (string) $url['path'],
                'title' => $title,
                'platform' => $video->platform,
                'external_id' => $video->externalVideoId,
                'thumbnail_url' => $thumbnail['url'] ?? null,
                'thumbnail' => $thumbnail,
                'published_at' => $this->publishedAt($source),
                'summary' => trim((string) ($editorial['summary'] ?? '')),
            ],
            'frontend_available' => true,
            'public_eligible' => true,
            'blockers' => [],
        ];
    }

    private function publishedAt(array $source): ?string
    {
        $value = $source['published_at'] ?? null;
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
