<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\SemanticClaimCandidateGuard;
use PHPUnit\Framework\TestCase;

final class SemanticClaimCandidateGuardTest extends TestCase
{
    public function test_raw_explicit_user_knowledge_is_admitted(): void
    {
        $result = (new SemanticClaimCandidateGuard())->evaluate([
            'text' => 'Một phát biểu do người dùng cung cấp.',
            'candidate_kind' => 'user_statement',
            'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            'raw_or_derived' => 'RAW',
        ], $this->interpretation());

        self::assertSame(['status' => 'ALLOWED', 'reason' => 'RAW_EXPLICIT_USER_KNOWLEDGE'], $result);
    }

    public function test_dictionary_owner_input_remains_review_required(): void
    {
        $result = (new SemanticClaimCandidateGuard())->evaluate([
            'text' => 'Một phát biểu do người dùng cung cấp.',
            'candidate_kind' => 'user_statement',
            'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            'raw_or_derived' => 'RAW',
        ], $this->interpretation(['dictionary_owner_commands' => [['operation' => 'define']]]));

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame(['KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED'], $result['blockers']);
    }

    public function test_derived_user_statement_remains_review_required(): void
    {
        $result = (new SemanticClaimCandidateGuard())->evaluate([
            'text' => 'Một phát biểu trích từ nội dung suy diễn.',
            'candidate_kind' => 'user_statement',
            'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            'raw_or_derived' => 'DERIVED',
        ], $this->interpretation());

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame(['KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED'], $result['blockers']);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function interpretation(array $overrides = []): array
    {
        return ['structured_interpretation_packet' => array_replace([
            'semantic_assertions' => [],
            'dictionary_owner_commands' => [],
            'source_context' => ['raw_or_derived' => 'RAW'],
        ], $overrides)];
    }
}
