<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, SemanticNeed};
use PHPUnit\Framework\TestCase;

final class FacetAwareClaimRetrievalTest extends TestCase
{
    public function test_sparse_facets_keep_an_opportunity_before_global_pool_pruning(): void
    {
        $subject = ['id' => 'subject-1', 'type' => 'variant'];
        $rows = [];
        foreach (range(1, 100) as $index) {
            $rows[] = $this->claim('a-' . $index, 'facet-a', 100 - $index);
        }
        foreach (['facet-b', 'facet-c', 'facet-d'] as $facet) {
            $rows[] = $this->claim($facet . '-1', $facet, 1);
        }
        $engine = new ClaimRetrievalEngine(
            static fn (array $requested): array => ['status' => 'available', 'items' => []],
            static function (array $requested, array $neighborhood, array $need = []) use ($rows): array { return $rows; },
            limit: 4,
        );

        $result = $engine->retrieveForNeeds(
            ['result_limit' => 4],
            array_map(fn (string $facet): SemanticNeed => $this->need($subject, $facet), ['facet-a', 'facet-b', 'facet-c', 'facet-d']),
        );

        $facets = array_values(array_unique(array_column($result['items'], 'facet')));
        self::assertSame(['facet-a', 'facet-b', 'facet-c', 'facet-d'], $facets);
        self::assertSame(4, count($result['items']));
        self::assertSame(4, count($result['need_diagnostics']));
        self::assertGreaterThanOrEqual(1, $result['retrieval_diagnostics']['opportunity_allocated_per_need']);
    }

    public function test_same_claim_supports_multiple_needs_without_duplicate_canonical_identity(): void
    {
        $subject = ['id' => 'subject-1', 'type' => 'variant'];
        $shared = $this->claim('shared-1', 'facet-a', 10) + ['concept' => 'form'];
        $engine = new ClaimRetrievalEngine(
            static fn (array $requested): array => ['status' => 'available', 'items' => []],
            static function (array $requested, array $neighborhood, array $need = []) use ($shared): array { return [$shared]; },
        );

        $result = $engine->retrieveForNeeds(
            ['result_limit' => 10],
            [$this->need($subject, 'facet-a', 'form'), $this->need($subject, 'facet-a', 'form')],
        );

        self::assertCount(1, $result['items']);
        self::assertCount(1, $result['items'][0]['need_ids']);
        self::assertSame('shared-1', $result['items'][0]['claim_id']);
        self::assertSame(10, $result['items'][0]['claim_revision']);
        self::assertSame(['source-1'], $result['items'][0]['source_ids']);
    }

    public function test_legacy_single_subject_retrieval_keeps_existing_contract(): void
    {
        $engine = new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static fn (array $subject, array $neighborhood): array => [[
                'id' => 'legacy-1', 'revision' => 1, 'text' => 'supported facet-a',
                'subject_id' => 'subject-1', 'subject_type' => 'variant', 'scope' => 'variant', 'facet' => 'facet-a',
                'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE', 'relevance' => 1.0,
            ]],
        );

        $result = $engine->retrieve([
            'raw_input' => 'facet-a',
            'subject_resolution' => ['subjects' => [['id' => 'subject-1', 'type' => 'variant']]],
        ]);

        self::assertSame('available', $result['status']);
        self::assertSame('legacy-1', $result['items'][0]['claim_id']);
    }

    /** @return array<string,mixed> */
    private function claim(string $id, string $facet, float $relevance): array
    {
        return [
            'id' => $id,
            'revision' => 10,
            'text' => 'supported ' . $facet,
            'subject_id' => 'subject-1',
            'subject_type' => 'variant',
            'scope' => 'variant',
            'facet' => $facet,
            'provenance' => 'CATALOG_SUPPORTED',
            'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            'source_ids' => ['source-1'],
            'evidence_ids' => ['evidence-1'],
            'relevance' => $relevance,
        ];
    }

    private function need(array $subject, string $facet, string $concept = 'concept'): SemanticNeed
    {
        return SemanticNeed::fromArray([
            'canonical_subject' => $subject,
            'facet_key' => $facet,
            'concept_key' => $concept,
            'scope' => 'variant',
            'intent' => $facet,
            'origin' => 'USER_EXPLICIT',
            'confidence' => 0.9,
            'evidence_requirement' => 'SUPPORTED_WITHIN_SCOPE',
        ]);
    }
}
