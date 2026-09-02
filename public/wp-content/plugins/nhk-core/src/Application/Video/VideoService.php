<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\{Video, VideoException};

final class VideoService
{
    public function __construct(private VideoRepository $videos)
    {
    }

    public function ingestUrl(string $url, string $title = '', array $metadata = [], ?string $thumbnailMediaId = null, ?string $canonicalId = null): Video
    {
        $candidate = Video::fromUrl($url, $title, $metadata, $thumbnailMediaId, $canonicalId);
        $existing = $this->videos->findByExternalReference($candidate->platform, $candidate->externalVideoId);
        if ($existing) {
            if ($existing->canonicalUrl === $candidate->canonicalUrl) return $existing;
            throw new VideoException('Video external reference already maps to another canonical URL.');
        }
        return $this->videos->create($candidate);
    }

    public function update(string $id, string $title, array $metadata, ?string $thumbnailMediaId, int $revision): Video
    {
        $current = $this->videos->findByCanonicalId($id);
        if (!$current) throw new VideoException('Video not found.');
        return $this->videos->update(new Video($current->canonicalId, $current->platform, $current->externalVideoId, $current->canonicalUrl, $title, $metadata, $thumbnailMediaId, $current->active, $current->revision), $revision);
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
}
