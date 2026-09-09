<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaUsageReconciler;
use NHK\Core\Domain\Media\MediaUsage;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaUsageReconcilerTest extends TestCase
{
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
}
