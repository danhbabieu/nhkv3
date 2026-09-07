<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Dictionary\DictionaryObservationRegistry;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\{Video, VideoException};

final class VideoService
{
    public function __construct(private VideoRepository $videos, private $dictionaryObserver = null) {}

    public function ingestUrl(string $url, string $title = '', array $metadata = [], ?string $thumbnailMediaId = null, ?string $canonicalId = null, bool $active = true): Video
    {
        $normalized = Video::fromUrl($url);
        $candidate = new Video(
            $canonicalId ?? \NHK\Core\Shared\Uuid\UuidCodec::newV7(),
            $normalized->platform,
            $normalized->externalVideoId,
            $normalized->canonicalUrl,
            $title,
            $metadata,
            $thumbnailMediaId,
            $active,
        );
        $existing = $this->videos->findByExternalReference($candidate->platform, $candidate->externalVideoId);
        if ($existing) {
            if ($existing->canonicalUrl === $candidate->canonicalUrl) return $existing;
            throw new VideoException('Video external reference already maps to another canonical URL.');
        }
        $video = $this->videos->create($candidate);
        $this->observe($video);
        return $video;
    }

    public function activateAfterSemanticAttachments(Video $video): Video
    {
        if ($video->active) return $video;
        return $this->videos->update(new Video(
            $video->canonicalId,
            $video->platform,
            $video->externalVideoId,
            $video->canonicalUrl,
            $video->title,
            $video->metadata,
            $video->thumbnailMediaId,
            true,
            $video->revision,
        ), $video->revision);
    }

    public function update(string $id, string $title, array $metadata, ?string $thumbnailMediaId, int $revision): Video
    {
        $current = $this->videos->findByCanonicalId($id);
        if (!$current) throw new VideoException('Video not found.');
        $video = $this->videos->update(new Video($current->canonicalId, $current->platform, $current->externalVideoId, $current->canonicalUrl, $title, $metadata, $thumbnailMediaId, $current->active, $current->revision), $revision);
        $this->observe($video);
        return $video;
    }

    public function retire(string $id, int $revision): Video { return $this->changeState($id, $revision, false); }
    public function reactivate(string $id, int $revision): Video { return $this->changeState($id, $revision, true); }

    private function changeState(string $id, int $revision, bool $active): Video
    {
        $current = $this->videos->findByCanonicalId($id);
        if (!$current) throw new VideoException('Video not found.');
        if ($current->active === $active) return $current;
        return $this->videos->update(new Video($current->canonicalId, $current->platform, $current->externalVideoId, $current->canonicalUrl, $current->title, $current->metadata, $current->thumbnailMediaId, $active, $current->revision), $revision);
    }

    private function observe(Video $video): void
    {
        $source = is_array($video->metadata['source'] ?? null) ? $video->metadata['source'] : [];
        $editorial = is_array($video->metadata['editorial'] ?? null) ? $video->metadata['editorial'] : [];
        $parts = [$video->title, (string) ($source['source_title'] ?? ''), (string) ($source['source_description'] ?? ''), (string) ($editorial['summary'] ?? '')];
        foreach ((array) ($source['tags'] ?? []) as $tag) if (is_string($tag)) $parts[] = $tag;
        $text = implode("\n", array_values(array_filter(array_map('trim', $parts))));
        $context = ['platform' => $video->platform, 'external_video_id' => $video->externalVideoId, 'semantic_attachments' => $video->metadata['semantic_attachments'] ?? []];
        if (is_callable($this->dictionaryObserver)) {
            try { ($this->dictionaryObserver)('VIDEO', $video->canonicalId, $text, $context); } catch (\Throwable) {}
            return;
        }
        DictionaryObservationRegistry::observe('VIDEO', $video->canonicalId, $text, $context);
    }
}
