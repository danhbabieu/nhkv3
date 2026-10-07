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

    public function test_dictionary_command_handoff_uses_structured_semantic_assertion_instead_of_raw_sentence(): void
    {
        $result = (new TextInputInterpreter())->interpret(
            'Bổ sung vào từ điển "Kính kim cương" nghĩa là một loại kính rào, ghi nhận năm 2020.',
        );

        self::assertSame(['ghi nhận năm 2020'], array_column($result['user_claim_candidates'], 'text'));
        self::assertSame(['ghi nhận năm 2020'], array_column($result['structured_interpretation_packet']['knowledge_delta_candidates'], 'text'));
        self::assertNotContains('Kính kim cương', $result['entity_mentions']);
        self::assertNotContains('Bổ sung', $result['entity_mentions']);
    }

    public function test_lexical_only_dictionary_command_has_no_semantic_candidate(): void
    {
        $result = (new TextInputInterpreter())->interpret(
            'Bổ sung vào từ điển "Kính kim cương" nghĩa là một loại kính rào.',
        );

        self::assertSame([], $result['user_claim_candidates']);
        self::assertSame([], $result['structured_interpretation_packet']['knowledge_delta_candidates']);
    }

    public function test_explicit_semantic_observation_survives_lexical_command_with_lineage(): void
    {
        $result = (new TextInputInterpreter())->interpret(
            'Bổ sung vào từ điển X nghĩa là Y.',
            [],
            [],
            [
                'raw_input_reference' => 'capture:1',
                'source_identity' => ['source_id' => 'capture:1'],
                'lineage' => ['parent' => 'request:1'],
            ],
            [[
                'text' => 'X được ghi nhận năm 1954.',
                'origin' => 'EXPLICIT_USER_KNOWLEDGE',
                'scope' => 'model',
            ]],
        );

        self::assertSame('X được ghi nhận năm 1954.', $result['user_claim_candidates'][0]['text']);
        self::assertSame('EXPLICIT_USER_KNOWLEDGE', $result['user_claim_candidates'][0]['provenance']);
        self::assertSame('model', $result['user_claim_candidates'][0]['scope']);
        self::assertSame('capture:1', $result['user_claim_candidates'][0]['raw_input_reference']);
        self::assertSame(['parent' => 'request:1'], $result['user_claim_candidates'][0]['lineage']);
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
