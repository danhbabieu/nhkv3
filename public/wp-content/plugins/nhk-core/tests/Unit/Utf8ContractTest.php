<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Shared\Encoding\{Utf8Contract, Utf8String, Utf8ValidationException};
use PHPUnit\Framework\TestCase;

final class Utf8ContractTest extends TestCase
{
    public function test_nested_editorial_packet_round_trips_vietnamese_and_punctuation_exactly(): void
    {
        $packet = [
            'title' => '“Đánh mượn” — Odo 24',
            'excerpt' => 'Mô tả côn và búa; có en–dash và em—dash.',
            'body' => "# Cấu tạo\n\nĐánh mượn côn và búa.\nDấu nháy “thông minh” và apostrophe’s.",
            'observations' => [['text' => 'Westminster, 6 côn', 'source' => 'capture']],
            'diagnostics' => ['quality' => ['reason' => 'MALFORMED_SENTENCE_JOIN']],
        ];

        $encoded = Utf8Contract::encode($packet, 'test.packet');
        self::assertSame($packet, Utf8Contract::decodeArray($encoded, 'test.packet'));
        self::assertSame('“Đánh mượn” mô tả cách bộ máy sử dụng côn và búa.', '“Đánh mượn” mô tả cách bộ máy sử dụng côn và búa.');
    }

    /** @dataProvider unicodeStringProvider */
    public function test_unicode_matrix_is_accepted_without_transliteration(string $value): void
    {
        self::assertSame($value, Utf8Contract::decodeArray(Utf8Contract::encode(['value' => $value], 'test.matrix'), 'test.matrix')['value']);
    }

    public function test_invalid_utf8_reports_bounded_producer_and_field(): void
    {
        $this->expectException(Utf8ValidationException::class);
        try {
            Utf8Contract::assertValid(['body' => "\xC3\x28"], 'mcp.capture.ingest', 'arguments');
        } catch (Utf8ValidationException $error) {
            self::assertSame('mcp.capture.ingest', $error->producer);
            self::assertSame('arguments[body]', $error->path);
            throw $error;
        }
    }

    public function test_unicode_truncation_never_cuts_a_multibyte_character(): void
    {
        $value = 'Đánh mượn “búa” — Westminster';
        self::assertSame('Đánh mượn “b', Utf8String::truncate($value, 12, 'test.truncate', 'value'));
        self::assertSame('Đánh mượn “b', Utf8String::slice($value, 0, 12, 'test.slice', 'value'));
        self::assertSame(1, preg_match('//u', Utf8String::truncate($value, 12)));
        self::assertStringContainsString('Đánh mượn', Utf8String::truncate($value, 12));
    }

    /** @return list<array{string}> */
    public static function unicodeStringProvider(): array
    {
        return [
            ['Đánh mượn côn và búa'],
            ['“smart quotes” — en–dash'],
            ["apostrophe’s\nMarkdown # heading"],
            ['ASCII 24 / mixed Unicode: Odo 24, Westminster'],
        ];
    }
}
