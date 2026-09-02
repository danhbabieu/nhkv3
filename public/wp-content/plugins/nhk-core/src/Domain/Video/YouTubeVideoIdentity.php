<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;

final readonly class YouTubeVideoIdentity
{
    public function __construct(
        public string $platform,
        public string $videoId,
        public string $canonicalUrl,
    ) {
        if ($platform !== 'youtube' || !preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) || $canonicalUrl !== 'https://www.youtube.com/watch?v=' . $videoId) {
            throw new InvalidVideoReference('YouTube video identity is invalid.');
        }
    }
}
