<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaUsageReconciler;
use NHK\Core\Domain\Media\MediaUsage;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaUsageReconcilerTest extends TestCase
{
    public function test_three_image_article_plan_keeps_featured_inline_and_supporting_usage_distinct(): void
    {
        $featured = UuidCodec::newV7();
        $inline = UuidCodec::newV7();
        $detail = UuidCodec::newV7();
        $result = (new MediaUsageReconciler())->plan('wp_post', '1:711', [], [
            ['role' => 'featured_primary', 'media_id' => $featured, 'placement_key' => 'capture:front'],
            ['role' => 'inline_primary', 'media_id' => $inline, 'placement_key' => 'capture:back'],
            ['role' => 'inline_supporting', 'media_id' => $detail, 'sort_order' => 2, 'placement_key' => 'capture:dial'],
        ]);

        self::assertSame('PLANNED', $result['status']);
        self::assertSame(['featured_primary', 'inline_primary', 'inline_supporting'], array_column($result['actions'], 'role'));
        self::assertSame([$featured, $inline, $detail], array_column($result['actions'], 'media_id'));
        self::assertSame(['ADD', 'ADD', 'ADD'], array_column($result['actions'], 'action'));
    }

    public function test_plan_is_deterministic_and_exposes_keep_add_update_and_retire(): void
    {
        $old = new MediaUsage(UuidCodec::newV7(), UuidCodec::newV7(), 'wp_post', '1:300', 'inline_primary', 0, 'Alt cũ');
        $retired = new MediaUsage(UuidCodec::newV7(), UuidCodec::newV7(), 'wp_post', '1:300', 'evidence');
        $newMedia = UuidCodec::newV7();
        $result = (new MediaUsageReconciler())->plan('wp_post', '1:300', [$retired, $old], [
            ['role' => 'inline_primary', 'media_id' => $old->mediaId, 'alt_text' => 'Alt mới'],
            ['role' => 'featured_primary', 'media_id' => $newMedia],
        ]);

        self::assertSame('PLANNED', $result['status']);
        self::assertSame(['evidence', 'featured_primary', 'inline_primary'], array_column($result['actions'], 'role'));
        self::assertSame(['RETIRE', 'ADD', 'UPDATE'], array_column($result['actions'], 'action'));
        self::assertSame($old->usageId, $result['actions'][2]['usage_id']);
    }

    public function test_conflict_is_fail_closed_and_requires_owner_review(): void
    {
        $first = new MediaUsage(UuidCodec::newV7(), UuidCodec::newV7(), 'wp_post', '1:300', 'inline_primary');
        $second = new MediaUsage(UuidCodec::newV7(), UuidCodec::newV7(), 'wp_post', '1:300', 'inline_primary');
        $result = (new MediaUsageReconciler())->plan('wp_post', '1:300', [$first, $second], []);

        self::assertSame('OWNER_REVIEW_REQUIRED', $result['status']);
        self::assertSame('CONFLICT', $result['actions'][0]['action']);
        self::assertSame('OWNER_REVIEW_REQUIRED', $result['actions'][1]['action']);
    }

    public function test_explicit_current_media_replaces_stale_active_slot(): void
    {
        $oldMedia = UuidCodec::newV7();
        $newMedia = UuidCodec::newV7();
        $current = new MediaUsage(UuidCodec::newV7(), $oldMedia, 'wp_post', '1:301', 'featured_primary');
        $result = (new MediaUsageReconciler())->plan('wp_post', '1:301', [$current], [['role' => 'featured_primary', 'media_id' => $newMedia, 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED']]);
        self::assertSame('REPLACE', $result['actions'][0]['action']);
        self::assertSame($newMedia, $result['actions'][0]['media_id']);
    }
}
