<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\Media\MediaUsage;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaUsagePlacementTest extends TestCase
{
    public function test_anchor_is_article_scoped_and_survives_reordering(): void
    {
        $mediaId = UuidCodec::newV7();
        $usageId = UuidCodec::newV7();
        $first = new MediaUsage($usageId, $mediaId, 'wp_post', '1:101', 'inline_supporting', 0, '', '', [], '', 1, 'detail-front');
        $reordered = new MediaUsage($usageId, $mediaId, 'wp_post', '1:101', 'inline_supporting', 9, '', '', [], '', 1, 'detail-front');
        $otherArticle = new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:102', 'inline_supporting', 0, '', '', [], '', 1, 'detail-front');

        self::assertSame($first->placementAnchor(), $reordered->placementAnchor());
        self::assertNotSame($first->placementAnchor(), $otherArticle->placementAnchor());
        self::assertStringStartsWith('nhk-placement-', $first->placementAnchor());
        self::assertSame(64, strlen(substr($first->placementAnchor(), strlen('nhk-placement-'))));
    }
}
