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
}
