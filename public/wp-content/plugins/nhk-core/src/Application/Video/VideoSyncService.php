<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Domain\Video\{Video, YouTubeSourceSnapshot};

final readonly class VideoSyncResult
{
    public function __construct(public string $status, public array $changedFields = [], public bool $reconciliationRequired = false)
    {
    }
}

final class VideoSyncService
{
    public function compare(Video $video, YouTubeSourceSnapshot $snapshot): VideoSyncResult
    {
        $old = is_array($video->metadata['source_snapshot'] ?? null) ? YouTubeSourceSnapshot::fromArray($video->metadata['source_snapshot']) : null;
        if ($snapshot->availability !== 'available') return new VideoSyncResult('SOURCE_UNAVAILABLE', ['availability'], true);
        if ($old === null) return new VideoSyncResult('REVIEW_REQUIRED', ['source_snapshot'], true);
        $fields = ['sourceTitle' => 'source_title', 'sourceDescription' => 'source_description', 'thumbnailUrls' => 'thumbnail_urls', 'availability' => 'availability', 'embeddable' => 'embeddable', 'durationSeconds' => 'duration_seconds', 'publishedAt' => 'published_at', 'tags' => 'tags'];
        $changed = [];
        foreach ($fields as $property => $field) if ($old->{$property} !== $snapshot->{$property}) $changed[] = $field;
        return $changed === [] ? new VideoSyncResult('NO_CHANGE') : new VideoSyncResult('SOURCE_CHANGED', $changed, true);
    }
}
