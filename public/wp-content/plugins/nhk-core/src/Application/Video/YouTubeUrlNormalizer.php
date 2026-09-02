<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Domain\Video\{InvalidVideoReference, YouTubeVideoIdentity};

final class YouTubeUrlNormalizer
{
    public static function normalize(string $url): YouTubeVideoIdentity
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || isset($parts['user'], $parts['pass'], $parts['port'])) {
            throw new InvalidVideoReference('Only a valid YouTube URL is supported.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $videoId = null;
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (in_array($path, ['/watch', '/playlist'], true) && isset($query['v']) && is_string($query['v'])) $videoId = $query['v'];
            if (preg_match('#^/(?:shorts|embed)/([A-Za-z0-9_-]{11})/?$#', $path, $match) === 1) $videoId = $match[1];
        } elseif ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{11})/?$#', $path, $match) === 1) {
            $videoId = $match[1];
        }
        if (!is_string($videoId) || preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) throw new InvalidVideoReference('Only a valid YouTube video URL is supported.');
        return new YouTubeVideoIdentity('youtube', $videoId, 'https://www.youtube.com/watch?v=' . $videoId);
    }
}
