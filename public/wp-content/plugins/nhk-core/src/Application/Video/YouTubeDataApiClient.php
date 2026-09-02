<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Domain\Video\{VideoException, YouTubeVideoIdentity};

final class YouTubeDataApiClient
{
    /** @param callable(string,array<string,mixed>):mixed|null $http */
    public function __construct(private ?string $apiKey = null, private $http = null)
    {
    }

    /** @return array<string,mixed> */
    public function fetch(YouTubeVideoIdentity $identity): array
    {
        $key = trim($this->apiKey ?? (string) getenv('NHK_YOUTUBE_API_KEY'));
        if ($key === '') throw new VideoException('YOUTUBE_API_NOT_CONFIGURED');
        $url = 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query(['part' => 'snippet,contentDetails,status,liveStreamingDetails', 'id' => $identity->videoId, 'key' => $key], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http !== null ? ($this->http)($url, ['timeout' => 8]) : (function_exists('wp_remote_get') ? wp_remote_get($url, ['timeout' => 8]) : null);
        if ($response === null || (function_exists('is_wp_error') && is_wp_error($response))) throw new VideoException('SOURCE_FETCH_FAILED');
        $status = is_array($response) ? (int) ($response['response']['code'] ?? $response['status'] ?? 0) : (function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0);
        if ($status >= 400) throw new VideoException($status === 429 ? 'API_RATE_LIMIT' : 'SOURCE_FETCH_FAILED');
        $body = is_array($response) && isset($response['body']) ? $response['body'] : (function_exists('wp_remote_retrieve_body') ? wp_remote_retrieve_body($response) : '');
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) throw new VideoException('SOURCE_FETCH_FAILED');
        if (isset($decoded['error'])) throw new VideoException('SOURCE_FETCH_FAILED');
        $item = is_array($decoded['items'][0] ?? null) ? $decoded['items'][0] : null;
        if ($item === null) return ['availability' => 'deleted'];
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $details = is_array($item['contentDetails'] ?? null) ? $item['contentDetails'] : [];
        $status = is_array($item['status'] ?? null) ? $item['status'] : [];
        return [
            'channel_id' => $snippet['channelId'] ?? null, 'channel_title' => $snippet['channelTitle'] ?? null,
            'title' => $snippet['title'] ?? null, 'description' => $snippet['description'] ?? null,
            'published_at' => $snippet['publishedAt'] ?? null, 'duration_seconds' => $this->duration((string) ($details['duration'] ?? '')),
            'thumbnails' => $this->thumbnailUrls($snippet['thumbnails'] ?? []), 'tags' => $snippet['tags'] ?? [],
            'default_language' => $snippet['defaultLanguage'] ?? ($snippet['defaultAudioLanguage'] ?? null),
            'caption_availability' => (($details['caption'] ?? 'false') === 'true') ? 'available' : 'unavailable',
            'embeddable' => array_key_exists('embeddable', $status) ? (bool) $status['embeddable'] : null,
            'availability' => ($status['privacyStatus'] ?? 'public') === 'private' ? 'private' : (($status['embeddable'] ?? true) ? 'available' : 'embed_disabled'),
            'live_state' => ($snippet['liveBroadcastContent'] ?? 'none') === 'live' ? 'live' : (($snippet['liveBroadcastContent'] ?? 'none') === 'upcoming' ? 'upcoming' : 'none'),
            'fetched_at' => gmdate('c'),
        ];
    }

    /** @return list<string> */
    private function thumbnailUrls(mixed $thumbnails): array
    {
        if (!is_array($thumbnails)) return [];
        return array_values(array_filter(array_map(static fn (mixed $item): string => is_array($item) ? (string) ($item['url'] ?? '') : '', $thumbnails), static fn (string $url): bool => $url !== ''));
    }

    private function duration(string $iso): ?int
    {
        if ($iso === '') return null;
        if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $match) !== 1) return null;
        return ((int) ($match[1] ?? 0) * 3600) + ((int) ($match[2] ?? 0) * 60) + (int) ($match[3] ?? 0);
    }
}
