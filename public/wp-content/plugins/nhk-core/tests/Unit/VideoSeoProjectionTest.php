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
}
