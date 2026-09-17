<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\TextInputInterpreter;
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
}
