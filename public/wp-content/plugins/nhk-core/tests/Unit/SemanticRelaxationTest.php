<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, SemanticNeed};
use PHPUnit\Framework\TestCase;

final class SemanticRelaxationTest extends TestCase
{
    public function test_exact_need_does_not_call_expansion(): void
    {
        $calls = 0;
        $engine = $this->engine([$this->claim('exact', 'facet-a', 'facet-a fact')], static function () use (&$calls): array { $calls++; return []; });

        $result = $engine->retrieveForNeeds([], [$this->need('facet-a')]);

        self::assertSame(0, $calls);
        self::assertSame('exact_candidates_available', $result['need_diagnostics'][0]['stop_reason']);
    }

    public function test_only_uncovered_need_relaxes_and_keeps_background_context_non_exact(): void
    {
        $expanded = [];
        $engine = $this->engine([
            $this->claim('exact', 'facet-a', 'facet-a fact'),
        ], static function (array $subject, SemanticNeed $need, string $tier, array $budget) use (&$expanded): array {
            $expanded[] = [$need->facetKey(), $tier];
            return [self::claimStatic('context', 'facet-b', 'facet-b broader context')];
        });

        $result = $engine->retrieveForNeeds([], [$this->need('facet-a'), $this->need('facet-b')]);

        self::assertSame([['facet-b', 'SUBJECT_BROADENED']], $expanded);
        self::assertSame('DIRECT_FACT', $result['items'][0]['editorial_treatment']);
        self::assertSame('SUBJECT_BROADENED', $result['items'][1]['retrieval_tier']);
        self::assertSame('applicable_relaxed', $result['items'][1]['coverage_kind']);
        self::assertNotSame('exact', $result['items'][1]['coverage_kind']);
    }

    public function test_uncovered_need_is_reported_without_fabricating_claim(): void
    {
        $engine = $this->engine([], static fn (array $subject, SemanticNeed $need, string $tier, array $budget): array => []);

        $result = $engine->retrieveForNeeds([], [$this->need('sparse')]);

        self::assertSame([], $result['items']);
        self::assertSame('uncovered', $result['need_diagnostics'][0]['stop_reason']);
        self::assertSame(0, $result['retrieval_diagnostics']['initial_candidates']);
    }

    public function test_reachable_but_inapplicable_neighbor_does_not_satisfy_need(): void
    {
        $engine = $this->engine([], static fn (array $subject, SemanticNeed $need, string $tier, array $budget): array => [[
            'id' => 'wrong', 'revision' => 2, 'text' => 'facet-b context', 'subject_id' => 'other-model', 'subject_type' => 'model',
            'scope' => 'model', 'facet' => 'facet-b', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            'relation_path' => [['source' => 'variant:subject-1', 'predicate' => 'variant_of', 'target' => 'model:not-the-claim-subject']],
        ]]);

        $result = $engine->retrieveForNeeds([], [$this->need('facet-b')]);

        self::assertSame([], $result['eligible_claims']);
        self::assertSame('uncovered', $result['need_diagnostics'][0]['stop_reason']);
    }

    private function engine(array $rows, callable $expansion): ClaimRetrievalEngine
    {
        return new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static function (array $subject, array $neighborhood, array $need = []) use ($rows): array { return $rows; },
            expansion: $expansion,
        );
    }

    private function need(string $facet): SemanticNeed
    {
        return SemanticNeed::fromArray([
            'canonical_subject' => ['id' => 'subject-1', 'type' => 'variant'],
            'facet_key' => $facet,
            'concept_key' => 'concept',
            'scope' => 'variant',
            'intent' => $facet,
            'origin' => 'USER_EXPLICIT',
            'confidence' => 0.9,
            'evidence_requirement' => 'SUPPORTED_WITHIN_SCOPE',
            'retrieval_policy' => ['opportunity_budget' => 2],
        ]);
    }

    private function claim(string $id, string $facet, string $text): array
    {
        return self::claimStatic($id, $facet, $text);
    }

    private static function claimStatic(string $id, string $facet, string $text): array
    {
        return [
            'id' => $id, 'revision' => 1, 'text' => $text,
            'subject_id' => 'subject-1', 'subject_type' => 'variant', 'scope' => 'variant',
            'facet' => $facet, 'provenance' => 'CATALOG_SUPPORTED',
            'evidence_status' => 'SUPPORTED_WITHIN_SCOPE', 'relevance' => 1.0,
        ];
    }
}
