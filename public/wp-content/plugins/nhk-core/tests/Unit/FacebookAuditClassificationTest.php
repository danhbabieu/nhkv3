<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use DateTimeImmutable;
use NHK\Core\Application\FacebookAudit\{FacebookAuditClassifier, FacebookDuplicateDetector};
use NHK\Core\Domain\FacebookAudit\FacebookValue;
use PHPUnit\Framework\TestCase;

final class FacebookAuditClassificationTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void { $this->now = new DateTimeImmutable('2026-10-10T00:00:00+07:00'); }

    public function testLowEngagementUsesContentTypeAndAgeBucket(): void
    {
        $rows = [
            $this->row('p1', '2026-10-01', 'PHOTO', 1, 'Nội dung một'),
            $this->row('p2', '2026-10-02', 'PHOTO', 5, 'Nội dung hai'),
            $this->row('p3', '2026-10-03', 'PHOTO', 20, 'Nội dung ba'),
            $this->row('p4', '2026-10-04', 'PHOTO', 40, 'Nội dung bốn'),
            $this->row('v1', '2026-10-01', 'VIDEO', 1, 'Video riêng'),
        ];

        $result = (new FacebookAuditClassifier(new FacebookDuplicateDetector(), $this->now))->classify($rows);

        self::assertSame('LOW_ENGAGEMENT', $result['rows'][0]['classification']);
        self::assertSame('KEEP', $result['rows'][4]['classification']);
        self::assertSame(1, $result['counts']['LOW_ENGAGEMENT']);
    }

    public function testDuplicateRequiresNormalizedTextAndMediaFingerprint(): void
    {
        $rows = [
            $this->row('p1', '2026-10-01', 'PHOTO', 10, '  Mẫu Đồng Hồ  ', ['m1', 'm2']),
            $this->row('p2', '2026-10-02', 'PHOTO', 12, 'mẫu đồng hồ', ['m2', 'm1']),
            $this->row('p3', '2026-10-03', 'PHOTO', 12, 'mẫu đồng hồ', ['m3']),
        ];

        $result = (new FacebookAuditClassifier(new FacebookDuplicateDetector(), $this->now))->classify($rows);

        self::assertSame('DUPLICATE', $result['rows'][0]['classification']);
        self::assertSame('DUPLICATE', $result['rows'][1]['classification']);
        self::assertSame('KEEP', $result['rows'][2]['classification']);
        self::assertCount(1, $result['duplicate_groups']);
    }

    public function testTrademarkIsReviewOnlyAndNotLegalConclusion(): void
    {
        $row = $this->row('p1', '2026-10-01', 'PHOTO', 10, 'Đồng hồ Omega cần kiểm tra');

        $result = (new FacebookAuditClassifier(new FacebookDuplicateDetector(), $this->now))->classify([$row], ['review_lexicon' => ['omega']]);

        self::assertSame('TRADEMARK_REVIEW', $result['rows'][0]['classification']);
        self::assertContains('TRADEMARK_REVIEW_REQUIRED', $result['rows'][0]['reason_codes']);
        self::assertNotContains('LEGAL_VIOLATION', $result['rows'][0]['reason_codes']);
    }

    public function testDeleteCandidateRequiresNoCommercialOrCustomerInterestSignal(): void
    {
        $commercial = $this->row('p1', '2025-01-01', 'PHOTO', 0, 'Bài cũ có giá bán', [], true, false);
        $candidate = $this->row('p2', '2025-01-01', 'PHOTO', 0, 'Bài cũ không còn thông tin', [], false, false);
        $result = (new FacebookAuditClassifier(new FacebookDuplicateDetector(), $this->now))->classify([$commercial, $candidate], ['delete_min_age_days' => 365]);

        self::assertSame('LOW_ENGAGEMENT', $result['rows'][0]['classification']);
        self::assertSame('DELETE_CANDIDATE', $result['rows'][1]['classification']);
        self::assertTrue($result['rows'][1]['delete_candidate']);
    }

    public function testMissingEngagementOrDateRemainsInsufficientData(): void
    {
        $missingMetric = $this->row('p1', '2026-10-01', 'PHOTO', null);
        $missingDate = $this->row('p2', null, 'PHOTO', 0);
        $missingMetric['reaction_count'] = FacebookValue::unavailable();
        $missingMetric['comment_count'] = FacebookValue::unavailable();
        $missingMetric['share_count'] = FacebookValue::unavailable();

        $result = (new FacebookAuditClassifier(new FacebookDuplicateDetector(), $this->now))->classify([$missingMetric, $missingDate]);

        self::assertSame('INSUFFICIENT_DATA', $result['rows'][0]['classification']);
        self::assertSame('INSUFFICIENT_DATA', $result['rows'][1]['classification']);
    }

    /** @return array<string,mixed> */
    private function row(string $id, ?string $date, string $type, ?int $reactions, string $text = 'Nội dung', array $media = [], bool $commercial = false, bool $customerInterest = false): array
    {
        return [
            'post_id' => $id,
            'published_at' => $date,
            'content_type' => $type,
            'text' => $text,
            'media_references' => $media,
            'reaction_count' => $reactions === null ? FacebookValue::unavailable() : FacebookValue::known($reactions),
            'comment_count' => FacebookValue::known(0),
            'share_count' => FacebookValue::known(0),
            'commercial_signal' => $commercial,
            'customer_interest_signal' => $customerInterest,
        ];
    }
}
