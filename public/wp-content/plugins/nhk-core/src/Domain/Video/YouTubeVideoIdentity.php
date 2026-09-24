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

    public static function privacyEmbedUrl(string $videoId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) throw new InvalidVideoReference('YouTube video identity is invalid.');
        return 'https://www.youtube-nocookie.com/embed/' . $videoId;
    }

    public static function isValidPrivacyEmbedUrl(string $url, string $videoId): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) return false;
        $parts = parse_url(trim($url));
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'www.youtube-nocookie.com'
            && (string) ($parts['path'] ?? '') === '/embed/' . $videoId
            && !isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass'], $parts['port']);
    }
}
