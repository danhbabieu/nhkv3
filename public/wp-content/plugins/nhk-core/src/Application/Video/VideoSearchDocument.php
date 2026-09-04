<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Video\Video;

final class VideoSearchDocument
{
    public function __construct(private AuthorityRepository $authority)
    {
    }

    public function isDiscoverable(Video $video): bool
    {
        if (!$video->active || !$video->hasValidPublicReference()) return false;
        $source = $this->source($video);
        return !isset($source['availability']) || in_array($source['availability'], ['available', 'unknown'], true);
    }

    public function title(Video $video): string
    {
        $editorial = $this->editorial($video);
        return trim((string) ($editorial['title'] ?? '')) ?: ($video->title ?: 'Video NHK');
    }

    public function publicUrl(Video $video): ?string
    {
        $result = (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector());
        $projected = (new PublicSeoProjection())->project($result, ['type' => 'VideoObject']);
        return $projected['indexable'] ? $projected['canonical'] : null;
    }

    /** @return list<string> */
    public function values(Video $video): array
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $source = $this->source($video);
        $editorial = $this->editorial($video);
        $values = [$this->title($video), $video->externalVideoId, $video->canonicalUrl, (string) ($editorial['summary'] ?? ''), (string) ($editorial['body'] ?? ''), (string) ($editorial['why_this_matters'] ?? ''), (string) ($source['source_title'] ?? ''), (string) ($source['source_description'] ?? ''), (string) ($metadata['category']['primary']['label'] ?? '')];
        foreach ((array) ($source['tags'] ?? []) as $tag) $values[] = (string) $tag;
        foreach ((array) ($metadata['semantic_attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) continue;
            $target = $this->authority->findByCanonicalId((string) ($attachment['target_key'] ?? $attachment['target_id'] ?? ''));
            if ($target !== null && $target->active()) $values[] = $target->canonicalName;
        }
        return $values;
    }

    /** @return array<string,mixed> */
    private function source(Video $video): array { return is_array($video->metadata['source_snapshot'] ?? null) ? $video->metadata['source_snapshot'] : []; }
    /** @return array<string,mixed> */
    private function editorial(Video $video): array { return is_array($video->metadata['editorial'] ?? null) ? $video->metadata['editorial'] : []; }
}
