<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Video\{VideoIdentityReader, VideoRepository};

final class VideoRepositoryIdentityReader implements VideoIdentityReader
{
    public function __construct(private VideoRepository $videos) {}

    public function findVideoIdentity(string $canonicalVideoId): ?array
    {
        $video = $this->videos->findByCanonicalId($canonicalVideoId);
        if ($video === null || trim($video->platform) === '' || trim($video->externalVideoId) === '') return null;
        return ['canonical_video_id' => $video->canonicalId, 'platform' => strtolower(trim($video->platform)), 'external_video_id' => trim($video->externalVideoId)];
    }
}
