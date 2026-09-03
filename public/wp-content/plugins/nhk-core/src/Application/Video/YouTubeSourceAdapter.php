<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Domain\Video\{TranscriptPolicy, VideoException, YouTubeSourceSnapshot};

final readonly class VideoSourceResolution
{
    public function __construct(public YouTubeSourceSnapshot $snapshot, public TranscriptPolicy $transcript, public ?string $diagnostic = null)
    {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $result = ['snapshot' => $this->snapshot->toArray(), 'transcript_policy' => $this->transcript->kind];
        if ($this->diagnostic !== null) $result['diagnostic'] = $this->diagnostic;
        if ($this->transcript->available()) $result['transcript'] = ['language' => $this->transcript->language, 'text_hash' => $this->transcript->hash, 'provenance' => $this->transcript->provenance];
        return $result;
    }
}

final class YouTubeSourceAdapter
{
    /** @param callable(object):array<string,mixed>|null $client */
    public function __construct(private $client = null)
    {
    }

    public function resolve(string $url): VideoSourceResolution
    {
        $identity = YouTubeUrlNormalizer::normalize($url);
        $diagnostic = null;
        try {
            $data = $this->client === null ? [] : ($this->client)($identity);
        } catch (VideoException $error) {
            $data = [];
            $diagnostic = $error->getMessage();
        }
        if (!is_array($data)) $data = [];
        $data['platform'] = 'youtube';
        $data['external_video_id'] = $identity->videoId;
        $data['canonical_source_url'] = $identity->canonicalUrl;
        $snapshot = YouTubeSourceSnapshot::fromArray($data);
        if ($snapshot->sourceHash === '') {
            $data = $snapshot->toArray();
            $data['source_hash'] = $snapshot->hash();
            $snapshot = YouTubeSourceSnapshot::fromArray($data);
        }
        return new VideoSourceResolution($snapshot, TranscriptPolicy::none(), $diagnostic ?: ($this->client === null ? 'YOUTUBE_API_NOT_CONFIGURED' : null));
    }
}
