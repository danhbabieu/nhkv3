<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Domain\Video\{VideoException, YouTubeVideoIdentity};

final class YouTubeDataApiClient
{
    /** @param callable(string,array<string,mixed>):mixed|null $http */
    public function __construct(private ?string $apiKey = null, private $http = null, private ?YouTubeApiConfiguration $configuration = null)
    {
    }

    /** @return array<string,mixed> */
    public function fetch(YouTubeVideoIdentity $identity): array
    {
        $key = trim($this->apiKey ?? ($this->configuration ?? new YouTubeApiConfiguration())->value() ?? '');
        if ($key === '') throw new VideoException('YOUTUBE_API_NOT_CONFIGURED');
        $url = 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query(['part' => 'snippet,contentDetails,status,liveStreamingDetails', 'id' => $identity->videoId, 'key' => $key], '', '&', PHP_QUERY_RFC3986);
        try {
            $response = $this->http !== null ? ($this->http)($url, ['timeout' => 8]) : (function_exists('wp_remote_get') ? wp_remote_get($url, ['timeout' => 8]) : null);
        } catch (\Throwable $error) {
            throw new VideoException($this->isTimeout($error->getMessage()) ? 'SOURCE_TIMEOUT' : 'SOURCE_FETCH_FAILED', 0, $error);
        }
        if ($response === null) throw new VideoException('SOURCE_HTTP_CLIENT_UNAVAILABLE');
        if (function_exists('is_wp_error') && is_wp_error($response)) throw new VideoException($this->isTimeout((string) $response->get_error_code() . ' ' . $response->get_error_message()) ? 'SOURCE_TIMEOUT' : 'SOURCE_FETCH_FAILED');
        $status = is_array($response) ? (int) ($response['response']['code'] ?? $response['status'] ?? 0) : (function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0);
        $body = is_array($response) && isset($response['body']) ? $response['body'] : (function_exists('wp_remote_retrieve_body') ? wp_remote_retrieve_body($response) : '');
        $decoded = json_decode((string) $body, true);
        if ($status >= 400) throw new VideoException($status === 429 ? 'API_RATE_LIMIT' : $this->apiError($decoded, $status));
        if (!is_array($decoded)) throw new VideoException('SOURCE_RESPONSE_INVALID');
        if (isset($decoded['error'])) throw new VideoException($this->apiError($decoded, $status));
        $item = is_array($decoded['items'][0] ?? null) ? $decoded['items'][0] : null;
        if ($item === null) return ['availability' => 'deleted', 'fetched_at' => gmdate('c')];
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
            'availability' => ($status['privacyStatus'] ?? null) === 'private' ? 'private' : (!array_key_exists('embeddable', $status) ? 'unknown' : ($status['embeddable'] ? 'available' : 'embed_disabled')),
            'live_state' => ($snippet['liveBroadcastContent'] ?? 'none') === 'live' ? 'live' : (($snippet['liveBroadcastContent'] ?? 'none') === 'upcoming' ? 'upcoming' : 'none'),
            'fetched_at' => gmdate('c'),
        ];
    }

    private function apiError(mixed $decoded, int $status): string
    {
        $reason = is_array($decoded) ? (string) ($decoded['error']['errors'][0]['reason'] ?? $decoded['error']['status'] ?? '') : '';
        $reason = preg_replace('/[^A-Za-z0-9_.-]/', '', $reason) ?: '';
        return $reason !== '' ? 'YOUTUBE_API_ERROR:' . $reason : 'SOURCE_HTTP_ERROR:' . max(0, $status);
    }

    private function isTimeout(string $message): bool
    {
        return preg_match('/timeout|timed out|operation timed out/i', $message) === 1;
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
