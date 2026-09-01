<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;
use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class Video
{
    public function __construct(
        public string $canonicalId,
        public string $platform,
        public string $externalVideoId,
        public string $canonicalUrl,
        public string $title = '',
        public array $metadata = [],
        public ?string $thumbnailMediaId = null,
        public bool $active = true,
        public int $revision = 1,
    ) {
        if (!UuidCodec::isValid($canonicalId) || $platform === '' || $externalVideoId === '' || filter_var($canonicalUrl, FILTER_VALIDATE_URL) === false) throw new InvalidVideoReference('Video identity is invalid.');
        if ($revision < 1) throw new InvalidVideoReference('Video revision must be positive.');
    }

    public static function fromUrl(string $url, string $title = '', array $metadata = [], ?string $thumbnailMediaId = null): self
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $id = null;
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $id = $query['v'] ?? null;
            if ($id === null && preg_match('#^/(?:shorts|embed)/([A-Za-z0-9_-]{11})#', (string) ($parts['path'] ?? ''), $match)) $id = $match[1];
        } elseif ($host === 'youtu.be') {
            $id = ltrim((string) ($parts['path'] ?? ''), '/');
        }
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) throw new InvalidVideoReference('Only a valid YouTube external video reference is supported.');
        return new self(UuidCodec::newV7(), 'youtube', $id, 'https://www.youtube.com/watch?v=' . $id, $title, $metadata, $thumbnailMediaId);
    }

    public function hasValidPublicReference(): bool
    {
        if ($this->platform !== 'youtube' || !preg_match('/^[A-Za-z0-9_-]{11}$/', $this->externalVideoId)) return false;
        try {
            $normalized = self::fromUrl($this->canonicalUrl);
        } catch (InvalidVideoReference) {
            return false;
        }
        return $normalized->externalVideoId === $this->externalVideoId
            && $normalized->canonicalUrl === $this->canonicalUrl;
    }
}
