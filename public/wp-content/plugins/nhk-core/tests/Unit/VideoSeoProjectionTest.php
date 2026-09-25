<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoSeoProjection;
use PHPUnit\Framework\TestCase;

final class VideoSeoProjectionTest extends TestCase
{
    public function test_ineligible_watch_page_does_not_emit_video_object(): void
    {
        $result = (new VideoSeoProjection())->project(['source' => ['external_video_id' => 'bad'], 'editorial' => ['title' => 'Video', 'summary' => 'Summary']], ['path' => null, 'eligible' => false, 'blockers' => ['VIDEO_THUMBNAIL_UNAVAILABLE']]);
        self::assertFalse($result['indexable']);
        self::assertSame([], $result['video_object']);
    }

    public function test_video_object_uses_persisted_best_thumbnail_selection_and_dimensions(): void
    {
        $result = (new VideoSeoProjection())->project([
            'source' => [
                'external_video_id' => 'VwP1AH9E3HA',
                'thumbnail_urls' => [
                    'https://i.ytimg.com/vi/VwP1AH9E3HA/default.jpg',
                    'https://i.ytimg.com/vi/VwP1AH9E3HA/hqdefault.jpg',
                ],
                'thumbnail_selection' => [
                    'url' => 'https://i.ytimg.com/vi/VwP1AH9E3HA/hqdefault.jpg',
                    'variant' => 'hqdefault',
                    'width' => 1280,
                    'height' => 720,
                ],
            ],
            'editorial' => ['title' => 'Video', 'summary' => 'Summary'],
            'seo' => ['title' => 'Video', 'description' => 'Summary'],
        ], ['path' => '/video/test/', 'eligible' => true, 'blockers' => []]);

        self::assertSame(['https://i.ytimg.com/vi/VwP1AH9E3HA/hqdefault.jpg'], $result['video_object']['thumbnailUrl']);
        self::assertSame(1280, $result['video_object']['thumbnailWidth']);
        self::assertSame('https://i.ytimg.com/vi/VwP1AH9E3HA/hqdefault.jpg', $result['open_graph']['image']);
    }

    public function test_video_object_prefers_governed_presentation_thumbnail_over_source_thumbnail(): void
    {
        $result = (new VideoSeoProjection())->project([
            'source' => [
                'external_video_id' => 'VwP1AH9E3HA',
                'thumbnail_selection' => ['url' => 'https://i.ytimg.com/vi/VwP1AH9E3HA/hqdefault.jpg', 'width' => 480, 'height' => 360],
            ],
            'presentation_thumbnail' => [
                'url' => 'https://example.test/anh/video-medium.webp',
                'full_url' => 'https://example.test/anh/video.webp',
                'width' => 1200,
                'height' => 800,
                'source' => 'media_usage',
            ],
            'editorial' => ['title' => 'Video', 'summary' => 'Summary'],
            'seo' => ['title' => 'Video', 'description' => 'Summary'],
        ], ['path' => '/video/test/', 'eligible' => true, 'blockers' => []]);

        self::assertSame(['https://example.test/anh/video.webp'], $result['video_object']['thumbnailUrl']);
        self::assertSame(1200, $result['video_object']['thumbnailWidth']);
        self::assertSame('https://example.test/anh/video.webp', $result['open_graph']['image']);
    }
}
