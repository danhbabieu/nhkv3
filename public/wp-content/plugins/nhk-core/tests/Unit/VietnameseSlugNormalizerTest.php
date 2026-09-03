<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Shared\Text\VietnameseSlugNormalizer;
use PHPUnit\Framework\TestCase;

final class VietnameseSlugNormalizerTest extends TestCase
{
    /** @dataProvider corpus */
    public function test_normalizes_corpus(string $input, string $expected): void
    {
        $result = (new VietnameseSlugNormalizer(120))->normalize($input);
        self::assertTrue($result->isValid());
        self::assertSame($expected, $result->value());
        self::assertNull($result->code());
    }

    public static function corpus(): iterable
    {
        yield ['Ô Đô', 'odo'];
        yield ['Đồng hồ cổ', 'dong-ho-co'];
        yield ['được', 'duoc'];
        yield ['người Việt', 'nguoi-viet'];
        yield ['sưu tập', 'suu-tap'];
        yield ['Âm thanh điểm nhạc', 'am-thanh-diem-nhac'];
        yield ['Vì sao người Việt gọi là 54?', 'vi-sao-nguoi-viet-goi-la-54'];
        yield ['Ô Đô 36/10 – Gai-Carillon', 'odo-36-10-gai-carillon'];
        yield ['Frère Jacques', 'frere-jacques'];
        yield ['Đồng hồ Pháp & Đức', 'dong-ho-phap-duc'];
    }

    public function test_collapses_rejected_runs_and_folds_case(): void
    {
        self::assertSame('hello-world', (new VietnameseSlugNormalizer(50))->normalize("  HELLO/// 🕰️ -- WORLD!!! ")->value());
    }

    public function test_rejects_empty_input_and_empty_result(): void
    {
        $normalizer = new VietnameseSlugNormalizer(20);
        self::assertSame('EMPTY_INPUT', $normalizer->normalize('')->code());
        self::assertSame('EMPTY_RESULT', $normalizer->normalize('🕰️!!!')->code());
    }

    public function test_rejects_over_limit_without_truncating(): void
    {
        $result = (new VietnameseSlugNormalizer(7))->normalize('abcdefgh');
        self::assertFalse($result->isValid());
        self::assertSame('TOO_LONG', $result->code());
        self::assertSame('abcdefgh', $result->value());
    }

    public function test_normalization_is_byte_identical_across_repeated_calls(): void
    {
        $normalizer = new VietnameseSlugNormalizer(120);
        $first = $normalizer->normalize('Đồng hồ cổ / Frère Jacques')->value();
        for ($i = 0; $i < 100; $i++) {
            self::assertSame($first, $normalizer->normalize('Đồng hồ cổ / Frère Jacques')->value());
        }
    }
}
