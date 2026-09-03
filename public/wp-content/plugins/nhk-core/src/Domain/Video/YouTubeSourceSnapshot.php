<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;

final readonly class YouTubeSourceSnapshot
{
    public function __construct(
        public string $platform,
        public string $externalVideoId,
        public string $canonicalSourceUrl,
        public ?string $channelId = null,
        public ?string $channelTitle = null,
        public ?string $sourceTitle = null,
        public ?string $sourceDescription = null,
        public ?string $publishedAt = null,
        public ?int $durationSeconds = null,
        public array $thumbnailUrls = [],
        public array $tags = [],
        public ?string $defaultLanguage = null,
        public string $captionAvailability = 'unknown',
        public ?bool $embeddable = null,
        public string $availability = 'unknown',
        public string $liveState = 'none',
        public ?string $fetchedAt = null,
        public string $sourceHash = '',
    ) {
        if ($platform !== 'youtube' || !preg_match('/^[A-Za-z0-9_-]{11}$/', $externalVideoId) || $canonicalSourceUrl !== 'https://www.youtube.com/watch?v=' . $externalVideoId) {
            throw new InvalidVideoReference('YouTube source snapshot identity is invalid.');
        }
        if ($durationSeconds !== null && ($durationSeconds < 0 || $durationSeconds > 86400)) throw new InvalidVideoReference('YouTube duration is outside the allowed bound.');
        if (!in_array($captionAvailability, ['unknown', 'available', 'unavailable'], true)) throw new InvalidVideoReference('YouTube caption availability is invalid.');
        if (!in_array($availability, ['unknown', 'available', 'private', 'deleted', 'region_blocked', 'embed_disabled'], true)) throw new InvalidVideoReference('YouTube availability is invalid.');
        if (!in_array($liveState, ['none', 'live', 'upcoming'], true)) throw new InvalidVideoReference('YouTube live state is invalid.');
        if (count($thumbnailUrls) > 10 || count($tags) > 100) throw new InvalidVideoReference('YouTube source metadata is too large.');
        foreach ($thumbnailUrls as $url) if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) throw new InvalidVideoReference('YouTube thumbnail URL is invalid.');
        foreach ($tags as $tag) if (!is_string($tag) || strlen($tag) > 200) throw new InvalidVideoReference('YouTube tag is invalid.');
        foreach ([$channelId, $channelTitle, $sourceTitle, $sourceDescription, $defaultLanguage, $fetchedAt, $sourceHash] as $text) if ($text !== null && strlen($text) > 200000) throw new InvalidVideoReference('YouTube source text is too large.');
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = (string) ($data['external_video_id'] ?? '');
        return new self(
            (string) ($data['platform'] ?? 'youtube'),
            $id,
            (string) ($data['canonical_source_url'] ?? 'https://www.youtube.com/watch?v=' . $id),
            self::nullableString($data['channel_id'] ?? null),
            self::nullableString($data['channel_title'] ?? null),
            self::nullableString($data['source_title'] ?? $data['title'] ?? null),
            self::nullableString($data['source_description'] ?? $data['description'] ?? null),
            self::nullableString($data['published_at'] ?? null),
            isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
            self::strings($data['thumbnail_urls'] ?? $data['thumbnails'] ?? []),
            self::strings($data['tags'] ?? []),
            self::nullableString($data['default_language'] ?? null),
            strtolower((string) ($data['caption_availability'] ?? 'unknown')),
            array_key_exists('embeddable', $data) && $data['embeddable'] !== null ? (bool) $data['embeddable'] : null,
            strtolower((string) ($data['availability'] ?? 'unknown')),
            strtolower((string) ($data['live_state'] ?? 'none')),
            self::nullableString($data['fetched_at'] ?? null),
            self::nullableString($data['source_hash'] ?? null) ?? '',
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'external_video_id' => $this->externalVideoId,
            'canonical_source_url' => $this->canonicalSourceUrl,
            'channel_id' => $this->channelId,
            'channel_title' => $this->channelTitle,
            'source_title' => $this->sourceTitle,
            'source_description' => $this->sourceDescription,
            'published_at' => $this->publishedAt,
            'duration_seconds' => $this->durationSeconds,
            'thumbnail_urls' => $this->thumbnailUrls,
            'tags' => $this->tags,
            'default_language' => $this->defaultLanguage,
            'caption_availability' => $this->captionAvailability,
            'embeddable' => $this->embeddable,
            'availability' => $this->availability,
            'live_state' => $this->liveState,
            'fetched_at' => $this->fetchedAt,
            'source_hash' => $this->sourceHash,
        ];
    }

    public function hash(): string
    {
        $data = $this->toArray();
        unset($data['source_hash']);
        ksort($data);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        if (!is_array($values)) return [];
        return array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $values), static fn (string $value): bool => $value !== ''));
    }
}
