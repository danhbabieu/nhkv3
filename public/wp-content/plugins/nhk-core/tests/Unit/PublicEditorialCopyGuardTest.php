<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Compliance\PublicEditorialCopyGuard;
use PHPUnit\Framework\TestCase;

final class PublicEditorialCopyGuardTest extends TestCase
{
    public function test_machine_context_is_rejected_but_ordinary_source_word_is_allowed(): void
    {
        $guard = new PublicEditorialCopyGuard();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PUBLIC_INTERNAL_JARGON_LEAK');
        $guard->assertSafe('SOURCE_FACT / subject_resolution_packet / canonical UUID');
    }

    public function test_ordinary_reader_facing_source_word_is_not_false_positive(): void
    {
        $guard = new PublicEditorialCopyGuard();

        self::assertSame('Theo nguồn tham chiếu, chiếc đồng hồ có mặt số xanh.', $guard->assertSafe('Theo nguồn tham chiếu, chiếc đồng hồ có mặt số xanh.'));
    }

    public function test_reader_facing_clock_terminology_is_not_classified_as_internal_jargon(): void
    {
        $guard = new PublicEditorialCopyGuard();

        self::assertSame([], $guard->findings([
            'body' => 'Đồng hồ công cộng có bộ máy, quả tạ, bộ thoát, truyền động, mặt số và điểm chuông; lịch sử kỹ thuật của chúng gắn với cộng đồng.',
        ]));
    }

    public function test_findings_report_the_exact_field_and_phrase_for_repairable_copy(): void
    {
        $findings = (new PublicEditorialCopyGuard())->findings([
            'body' => 'Trong bối cảnh tri thức NHK, đồng hồ công cộng được nhận diện.',
            'seo_description' => 'Đồng hồ công cộng qua một nguồn tham chiếu cụ thể.',
        ]);

        self::assertSame(['body', 'seo_description'], array_column($findings, 'field'));
        self::assertSame(['Trong bối cảnh tri thức NHK', 'nguồn tham chiếu cụ thể'], array_column($findings, 'phrase'));
        self::assertSame(['REPAIRABLE', 'REPAIRABLE'], array_column($findings, 'severity'));
    }
}
