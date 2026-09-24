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

    public function test_duplicate_heavy_storage_order_does_not_hide_later_useful_facet(): void
    {
        $subject = ['id' => 'subject-1', 'type' => 'variant'];
        $rows = [];
        for ($i = 0; $i < 60; $i++) $rows[] = $this->claim('duplicate-' . $i, 'facet-a', 0.1);
        $rows[] = $this->claim('useful-b', 'facet-b', 1.0);
        $engine = new ClaimRetrievalEngine(
            static fn (array $requested): array => ['status' => 'available', 'items' => []],
            static function (array $requested, array $neighborhood, array $need = []) use ($rows): array { return $rows; },
            limit: 4,
        );

        $result = $engine->retrieveForNeeds(['result_limit' => 4], [$this->need($subject, 'facet-a'), $this->need($subject, 'facet-b')]);

        self::assertContains('useful-b', array_column($result['items'], 'claim_id'));
    }

    public function test_large_need_retrieval_is_deterministic_and_diagnostics_do_not_include_raw_payloads(): void
    {
        $rows = [];
        for ($i = 0; $i < 1000; $i++) $rows[] = $this->claim('claim-' . $i, $i % 2 === 0 ? 'facet-a' : 'facet-b', 1.0);
        $engine = new ClaimRetrievalEngine(
            static fn (array $requested): array => ['status' => 'available', 'items' => []],
            static function (array $requested, array $neighborhood, array $need = []) use ($rows): array { return $rows; },
            limit: 20,
        );
        $needs = [$this->need(['id' => 'subject-1', 'type' => 'variant'], 'facet-a'), $this->need(['id' => 'subject-1', 'type' => 'variant'], 'facet-b')];

        $first = $engine->retrieveForNeeds(['result_limit' => 20], $needs);
        $second = $engine->retrieveForNeeds(['result_limit' => 20], $needs);

        self::assertSame($first['retrieval_diagnostics'], $second['retrieval_diagnostics']);
        self::assertLessThanOrEqual(20, count($first['items']));
        self::assertStringNotContainsString('raw_secret', json_encode($first['retrieval_diagnostics'], JSON_UNESCAPED_UNICODE));
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
