<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoThumbnailSelector;
use PHPUnit\Framework\TestCase;

final class VideoThumbnailSelectorTest extends TestCase
{
    public function test_highest_quality_usable_candidate_wins_in_governed_order(): void
    {
        $selector = new VideoThumbnailSelector(static function (string $url): array {
            return match (basename($url)) {
                'maxresdefault.jpg' => ['status' => 404, 'mime_type' => 'image/jpeg'],
                'sddefault.jpg' => ['status' => 200, 'mime_type' => 'image/jpeg', 'width' => 640, 'height' => 360],
                'hqdefault.jpg' => ['status' => 200, 'mime_type' => 'image/jpeg', 'width' => 480, 'height' => 360],
                default => ['status' => 200, 'mime_type' => 'image/jpeg', 'width' => 120, 'height' => 90],
            };
        });

        $selected = $selector->select([
            ['variant' => 'maxresdefault', 'url' => 'https://img.youtube.test/maxresdefault.jpg'],
            ['variant' => 'sddefault', 'url' => 'https://img.youtube.test/sddefault.jpg'],
            ['variant' => 'hqdefault', 'url' => 'https://img.youtube.test/hqdefault.jpg'],
        ]);

        self::assertSame('sddefault', $selected['variant']);
        self::assertSame('https://img.youtube.test/sddefault.jpg', $selected['url']);
        self::assertSame(640, $selected['width']);
        self::assertSame(360, $selected['height']);
    }

    public function test_filename_alone_cannot_make_a_tiny_maxres_candidate_win(): void
    {
        $selector = new VideoThumbnailSelector(static function (string $url): array {
            return str_contains($url, 'maxres')
                ? ['status' => 200, 'mime_type' => 'image/jpeg', 'width' => 120, 'height' => 90]
                : ['status' => 200, 'mime_type' => 'image/jpeg', 'width' => 1280, 'height' => 720];
        });

        $selected = $selector->select([
            ['variant' => 'maxresdefault', 'url' => 'https://img.youtube.test/maxresdefault.jpg'],
            ['variant' => 'hqdefault', 'url' => 'https://img.youtube.test/hqdefault.jpg'],
        ]);

        self::assertSame('hqdefault', $selected['variant']);
        self::assertSame(1280, $selected['width']);
    }

    public function test_invalid_or_placeholder_candidates_are_not_projected(): void
    {
        $selector = new VideoThumbnailSelector(static fn (string $url): array => ['status' => 200, 'mime_type' => 'text/html', 'width' => 1280, 'height' => 720]);

        self::assertSame([], $selector->select([
            ['variant' => 'maxresdefault', 'url' => 'https://img.youtube.test/maxresdefault.jpg'],
            ['variant' => 'default', 'url' => 'https://img.youtube.test/default.jpg'],
        ]));
    }

    public function test_persisted_selection_is_read_without_creating_media(): void
    {
        $selector = new VideoThumbnailSelector();

        self::assertSame(
            ['url' => 'https://img.youtube.test/sddefault.jpg', 'variant' => 'sddefault', 'width' => 1280, 'height' => 720],
            $selector->fromSource(['thumbnail_selection' => ['url' => 'https://img.youtube.test/sddefault.jpg', 'variant' => 'sddefault', 'width' => 1280, 'height' => 720]]),
        );
    }

    public function test_unprobed_youtube_variant_is_not_selected_by_filename_alone(): void
    {
        self::assertSame([], (new VideoThumbnailSelector())->fromSource([
            'thumbnail_urls' => ['https://i.ytimg.com/vi/example/default.jpg', 'https://i.ytimg.com/vi/example/maxresdefault.jpg'],
        ]));
    }

    public function test_compact_presentation_prefers_medium_candidate_without_changing_canonical_selection(): void
    {
        $selector = new VideoThumbnailSelector();
        self::assertSame('mqdefault', $selector->selectCompact([
            ['variant' => 'maxresdefault', 'url' => 'https://img.youtube.test/maxresdefault.jpg', 'width' => 1280, 'height' => 720],
            ['variant' => 'mqdefault', 'url' => 'https://img.youtube.test/mqdefault.jpg', 'width' => 320, 'height' => 180],
        ])['variant']);
        self::assertSame('maxresdefault', $selector->fromSource([
            'thumbnail_selection' => ['url' => 'https://img.youtube.test/maxresdefault.jpg', 'variant' => 'maxresdefault', 'width' => 1280, 'height' => 720],
        ])['variant']);
    }

    public function test_compact_presentation_falls_back_to_persisted_canonical_thumbnail(): void
    {
        $selected = (new VideoThumbnailSelector())->presentationFromSource([
            'thumbnail_selection' => ['url' => 'https://img.youtube.test/maxresdefault.jpg', 'variant' => 'maxresdefault', 'width' => 1280, 'height' => 720],
        ]);
        self::assertSame('https://img.youtube.test/maxresdefault.jpg', $selected['url']);
    }
}
