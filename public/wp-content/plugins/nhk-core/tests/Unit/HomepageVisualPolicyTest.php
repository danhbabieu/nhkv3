<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Presentation\HomepageVisualPolicy;
use PHPUnit\Framework\TestCase;

final class HomepageVisualPolicyTest extends TestCase
{
    public function test_persisted_compact_remote_candidate_is_rendered(): void
    {
        $visual = (new HomepageVisualPolicy())->resolve([
            'type' => 'video',
            'image_url' => 'https://img.example.test/mqdefault.jpg',
            'width' => 320,
            'height' => 180,
        ]);

        self::assertSame('image', $visual['visual_kind']);
        self::assertSame('compact_remote', $visual['visual_reason']);
        self::assertSame('https://img.example.test/mqdefault.jpg', $visual['image_url']);
    }

    public function test_oversized_remote_only_candidate_becomes_a_fallback_without_rewriting_url(): void
    {
        $visual = (new HomepageVisualPolicy())->resolve([
            'type' => 'video',
            'image_url' => 'https://img.example.test/maxresdefault.jpg',
            'width' => 1280,
            'height' => 720,
        ]);

        self::assertSame('fallback', $visual['visual_kind']);
        self::assertSame('oversized_remote_fallback', $visual['visual_reason']);
        self::assertNull($visual['image_url']);
    }

    public function test_local_attachment_with_responsive_delivery_is_safe_at_large_dimensions(): void
    {
        $visual = (new HomepageVisualPolicy())->resolve([
            'type' => 'article',
            'image_url' => '/wp-content/uploads/large.webp',
            'attachment_id' => 42,
            'srcset' => '/small.webp 320w, /large.webp 1200w',
            'sizes' => '(max-width: 640px) 100vw, 320px',
            'width' => 1200,
            'height' => 750,
        ]);

        self::assertSame('image', $visual['visual_kind']);
        self::assertSame('local_responsive', $visual['visual_reason']);
        self::assertSame('/small.webp 320w, /large.webp 1200w', $visual['image_srcset']);
    }
}
