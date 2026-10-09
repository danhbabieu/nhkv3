<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{SemanticClaimCandidateGuard, TextInputInterpreter};
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

    public function test_shared_interpreter_preserves_explicit_factual_assertion_for_governed_admission(): void
    {
        $interpreted = (new TextInputInterpreter())->interpret('Hermle được thành lập năm 1922.');
        $candidate = $interpreted['user_claim_candidates'][0];

        self::assertSame('user_statement', $candidate['candidate_kind']);
        self::assertSame('EXPLICIT_USER_KNOWLEDGE', $candidate['provenance']);
        self::assertSame('ALLOWED', (new SemanticClaimCandidateGuard())->evaluate($candidate, $interpreted)['status']);
    }

    public function test_shared_interpreter_keeps_unverified_inference_out_of_governed_admission(): void
    {
        $interpreted = (new TextInputInterpreter())->interpret('Có thể Hermle được thành lập năm 1922.');
        $candidate = $interpreted['user_claim_candidates'][0];
        $result = (new SemanticClaimCandidateGuard())->evaluate($candidate, $interpreted);

        self::assertSame('derived_candidate', $candidate['candidate_kind']);
        self::assertSame('SYSTEM_INFERENCE', $candidate['provenance']);
        self::assertSame('REVIEW_REQUIRED', $result['status']);
    }

    public function test_all_registered_authority_types_share_the_same_integrity_matrix(): void
    {
        $types = ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'];
        $guard = new SemanticClaimCandidateGuard();

        foreach ($types as $type) {
            $interpreted = (new TextInputInterpreter())->interpret('Được thành lập năm 1922.');
            $candidate = $interpreted['user_claim_candidates'][0] + [
                'subject_type' => $type,
                'scope' => $type === 'specimen' ? 'specimen_observation' : $type,
                'facet' => 'identity',
            ];

            self::assertSame('ALLOWED', $guard->evaluate($candidate, $interpreted)['status'], $type);
            self::assertSame('EXPLICIT_USER_KNOWLEDGE', $candidate['provenance'], $type);
        }

        foreach ([
            'Source: https://example.test/catalog',
            'Hãy kiểm tra lại nguồn trước khi ghi.',
            'Evidence: “Được ghi nhận trong hồ sơ.”',
            'Metadata: filename=clock.webp',
            'Có thể được thành lập năm 1922.',
            'Bổ sung định nghĩa cho carillon: một bộ chuông nhiều cao độ.',
        ] as $input) {
            $interpreted = (new TextInputInterpreter())->interpret($input);
            foreach ($interpreted['user_claim_candidates'] as $candidate) {
                self::assertSame('REVIEW_REQUIRED', $guard->evaluate($candidate, $interpreted)['status'], $input);
            }
            if ($interpreted['user_claim_candidates'] === []) self::assertSame([], $interpreted['user_claim_candidates'], $input);
        }
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
