<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, SemanticNeed, UniversalInputEnvelope};
use PHPUnit\Framework\TestCase;

final class UniversalFacetRetrievalTest extends TestCase
{
    public function test_retrieval_accepts_universal_input_and_preserves_one_claim_for_multiple_needs(): void
    {
        $engine = new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static fn (array $subject, array $neighborhood, array $need): array => [[
                'claim_id' => 'claim-shared',
                'revision' => 2,
                'text' => 'A documented shared fact.',
                'subject_id' => $subject['id'],
                'subject_type' => $subject['type'],
                'facet' => $need['facet_key'],
                'concept' => $need['concept_key'],
                'scope' => 'variant',
                'provenance' => 'SOURCE_EXPLICIT',
                'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            ]],
            2,
            10,
        );
        $input = UniversalInputEnvelope::fromArray(['body' => 'shared', 'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'variant']]]);
        $needs = [
            $this->need('form', 'dial_form'),
            $this->need('configuration', 'layout'),
        ];

        $result = $engine->retrieveForNeeds($input, $needs, ['result_limit' => 1]);

        self::assertCount(1, $result['items']);
        self::assertSame(['form', 'configuration'], array_column($result['need_diagnostics'], 'facet_key'));
        self::assertCount(2, $result['items'][0]['need_ids']);
        self::assertSame(2, $result['retrieval_diagnostics']['need_count']);
    }

    public function test_sparse_need_receives_an_opportunity_before_global_limit_and_unsafe_tier_is_skipped(): void
    {
        $engine = new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static fn (array $subject, array $neighborhood, array $need): array => $need['facet_key'] === 'configuration' ? [[
                'claim_id' => 'configuration-1', 'revision' => 1, 'text' => 'Configuration fact.', 'subject_id' => $subject['id'], 'subject_type' => $subject['type'], 'facet' => 'configuration', 'scope' => 'variant', 'provenance' => 'SOURCE_EXPLICIT', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            ]] : [],
            2,
            1,
            null,
            static fn (): array => [],
        );
        $input = UniversalInputEnvelope::fromArray(['subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'variant']]]);
        $need = SemanticNeed::fromArray([
            'canonical_subject' => ['id' => 'subject-1', 'type' => 'variant'],
            'facet_key' => 'configuration', 'concept_key' => 'layout', 'scope' => 'variant', 'origin' => 'USER_EXPLICIT', 'confidence' => 1.0,
            'retrieval_policy' => ['opportunity_budget' => 1, 'tiers' => ['EXACT']],
        ]);

        $result = $engine->retrieveForNeeds($input, [$need], ['result_limit' => 1]);

        self::assertSame('configuration', $result['items'][0]['facet']);
        self::assertSame('EXACT', $result['items'][0]['retrieval_tier']);
        self::assertSame('exact_candidates_available', $result['need_diagnostics'][0]['stop_reason']);
    }

    private function need(string $facet, string $concept): SemanticNeed
    {
        return SemanticNeed::fromArray([
            'canonical_subject' => ['id' => 'subject-1', 'type' => 'variant'],
            'facet_key' => $facet, 'concept_key' => $concept, 'scope' => 'variant', 'origin' => 'USER_EXPLICIT', 'confidence' => 1.0,
        ]);
    }
}
