<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\VisualOpportunityDetector;
use PHPUnit\Framework\TestCase;

final class VisualOpportunityDetectorTest extends TestCase
{
    private const SUBJECT = '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3321';

    public function test_detects_bounded_ranked_opportunities_using_registered_media_detail_keys(): void
    {
        $detector = new VisualOpportunityDetector();

        $opportunities = $detector->detect(
            'Bài viết mô tả mặt số, bộ búa, bộ côn và chi tiết thùng của đồng hồ.',
            [],
            ['status' => 'resolved', 'primary' => ['id' => self::SUBJECT, 'type' => 'variant', 'name' => 'Odo 36/8']],
        );

        self::assertCount(3, $opportunities);
        self::assertSame(['DIAL', 'HAMMER_BANK', 'ROD_BANK'], array_column($opportunities, 'feature_key'));
        self::assertSame('mặt số', $opportunities[0]['feature_label']);
        self::assertSame(self::SUBJECT, $opportunities[0]['subject']['id']);
        self::assertSame('variant', $opportunities[0]['scope']);
        self::assertSame('technical_detail', $opportunities[0]['visual_intent']);
        self::assertSame('DIAL', $opportunities[0]['recommended_view']);
        self::assertArrayHasKey('potential_reuse', $opportunities[0]);
        self::assertSame(1, $opportunities[0]['priority']);
    }

    public function test_requires_a_resolved_canonical_subject_and_preserves_human_feature_context(): void
    {
        $detector = new VisualOpportunityDetector();

        self::assertSame([], $detector->detect('Có một bộ máy đẹp.', [], ['status' => 'unresolved', 'primary' => null]));

        $opportunities = $detector->detect(
            'Có phong vũ biểu ở mặt trước.',
            [],
            ['status' => 'resolved', 'primary' => ['id' => self::SUBJECT, 'type' => 'specimen', 'name' => 'Mẫu thử']],
        );

        self::assertCount(1, $opportunities);
        self::assertSame('phong vũ biểu', $opportunities[0]['feature_label']);
        self::assertSame('COMPONENT_DETAIL', $opportunities[0]['feature_key']);
        self::assertSame('specimen_observation', $opportunities[0]['scope']);
        self::assertStringContainsString('phong vũ biểu', $opportunities[0]['reason']);
    }
}
