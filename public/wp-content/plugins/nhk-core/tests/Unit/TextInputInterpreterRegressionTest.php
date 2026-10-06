<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\TextInputInterpreter;
use NHK\Core\Shared\Encoding\Utf8ValidationException;
use PHPUnit\Framework\TestCase;

final class TextInputInterpreterRegressionTest extends TestCase
{
    public function test_unscoped_brand_fact_is_not_defaulted_to_variant(): void
    {
        $result = (new TextInputInterpreter())->interpret('Hermle được thành lập năm 1922.');

        self::assertNotSame('variant', $result['user_claim_candidates'][0]['scope']);
    }

    public function test_operator_instruction_does_not_become_knowledge_candidate(): void
    {
        $result = (new TextInputInterpreter())->interpret('Bổ sung các nguồn chính thức phục vụ hồ sơ thương hiệu.');

        self::assertSame([], $result['user_claim_candidates']);
        self::assertNotEmpty($result['non_semantic_context']['instructions']);
    }

    public function test_instruction_plus_fact_keeps_only_fact_candidate(): void
    {
        $result = (new TextInputInterpreter())->interpret('Bổ sung nguồn chính thức. Hermle được thành lập năm 1922.');

        self::assertCount(1, $result['user_claim_candidates']);
        self::assertStringContainsString('1922', $result['user_claim_candidates'][0]['text']);
    }

    public function test_factual_imperative_is_not_misclassified_as_operator_instruction(): void
    {
        $result = (new TextInputInterpreter())->interpret('Hãy ghi nhận rằng Hermle được thành lập năm 1922.');

        self::assertCount(1, $result['user_claim_candidates']);
    }

    /** @dataProvider unicodeBoundaryProvider */
    public function test_candidate_generation_preserves_valid_unicode_at_sentence_boundaries(string $text): void
    {
        $result = (new TextInputInterpreter())->interpret($text);

        self::assertNotEmpty($result['user_claim_candidates']);
        foreach ($result['user_claim_candidates'] as $candidate) {
            self::assertIsString($candidate['text']);
            self::assertSame(1, preg_match('//u', $candidate['text']));
        }
        self::assertSame(
            preg_replace('/\s+/u', ' ', trim($text)),
            preg_replace('/\s+/u', ' ', implode(' ', array_column($result['user_claim_candidates'], 'text'))),
        );
    }

    public function test_malformed_input_fails_at_interpretation_boundary(): void
    {
        $this->expectException(Utf8ValidationException::class);
        try {
            (new TextInputInterpreter())->interpret("Đánh mượn \xC3\x28.");
        } catch (Utf8ValidationException $error) {
            self::assertSame('semantic.interpretation', $error->producer);
            self::assertSame('input.text', $error->path);
            throw $error;
        }
    }

    public function test_unicode_safe_trim_removes_a_bullet_without_corrupting_a_curly_quote(): void
    {
        $result = (new TextInputInterpreter())->interpret('• “Đánh mượn” ở Westminster.');

        self::assertSame('“Đánh mượn” ở Westminster.', $result['user_claim_candidates'][0]['text']);
        self::assertSame(1, preg_match('//u', $result['user_claim_candidates'][0]['text']));
    }

    /** @return list<array{string}> */
    public static function unicodeBoundaryProvider(): array
    {
        return [
            ['ASCII sentence. Westminster remains unchanged.'],
            ['Đánh mượn ở Westminster. ÔĐô dùng búa.'],
            ['Cấu hình có búa và điểm giờ. Sonodo được ghi nhận.'],
            ['“Đánh mượn” — búa; dòng mới\n# Markdown heading.'],
            ["Ký tự kết hợp: e\u{0301} và tiếng Việt có dấu."],
        ];
    }
}
