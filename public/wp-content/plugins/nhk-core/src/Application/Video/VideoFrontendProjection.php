<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Domain\Video\Video;

/**
 * The read-only Video projection consumed by public frontend surfaces.
 *
 * This is deliberately derived from the canonical Video and persisted Public
 * Identity. It is not a second owner or a WordPress post projection.
 */
final class VideoFrontendProjection
{
    /** @var null|callable(Video,array<string,mixed>):?array<string,mixed> */
    private $representativeThumbnail;

    public function __construct(private ?PublicIdentityRepository $publicIdentities = null, ?callable $representativeThumbnail = null)
    {
        $this->representativeThumbnail = $representativeThumbnail;
    }

    /** @return array{item:?array<string,mixed>,frontend_available:bool,public_eligible:bool,blockers:list<string>} */
    public function project(Video $video): array
    {
        $url = (new VideoUrlPolicy($this->publicIdentities))->project($video, new VideoPublicContextSelector());
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
        $thumbnail = $this->thumbnail($video, $source);

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

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function thumbnail(Video $video, array $source): array
    {
        if (is_callable($this->representativeThumbnail)) {
            $candidate = ($this->representativeThumbnail)($video, $source);
            if (is_array($candidate)) {
                $url = trim((string) ($candidate['thumbnail_url'] ?? $candidate['url'] ?? ''));
                if ($url !== '') {
                    return [
                        'url' => $url,
                        'full_url' => trim((string) ($candidate['url'] ?? $url)),
                        'variant' => 'nhk_media_representative',
                        'width' => isset($candidate['width']) ? (int) $candidate['width'] : null,
                        'height' => isset($candidate['height']) ? (int) $candidate['height'] : null,
                        'media_id' => trim((string) ($candidate['media_id'] ?? '')) ?: null,
                        'asset_id' => trim((string) ($candidate['asset_id'] ?? '')) ?: null,
                        'alt' => trim((string) ($candidate['alt'] ?? '')),
                        'caption' => trim((string) ($candidate['caption'] ?? '')),
                        'srcset' => trim((string) ($candidate['srcset'] ?? '')) ?: null,
                        'sizes' => trim((string) ($candidate['sizes'] ?? '')) ?: null,
                        'source' => 'media_usage',
                    ];
                }
            }
        }

        $thumbnail = (new VideoThumbnailSelector())->presentationFromSource($source);
        if (($thumbnail['url'] ?? '') !== '') $thumbnail['source'] = 'external_source';
        return $thumbnail;
    }

    private function publishedAt(array $source): ?string
    {
        $value = $source['published_at'] ?? null;
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
