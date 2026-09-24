<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, SemanticNeed};
use PHPUnit\Framework\TestCase;

final class SemanticRetrievalDiagnosticsTest extends TestCase
{
    public function test_retrieval_diagnostics_are_bounded_deterministic_and_secret_safe(): void
    {
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = [
                'id' => 'claim-' . $i,
                'claim_id' => 'claim-' . $i,
                'subject_id' => 'subject-1',
                'subject_type' => 'model',
                'facet' => $i % 2 === 0 ? 'configuration' : 'appearance',
                'text' => ($i % 2 === 0 ? 'configuration layout' : 'appearance surface') . ' claim ' . $i,
                'scope' => 'model',
                'provenance' => 'CATALOG_SUPPORTED',
                'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
                'private_source_body' => 'raw_secret',
            ];
        }
        $engine = new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static function (array $subject, array $neighborhood, array $need = []) use ($rows): array { return $rows; },
            limit: 20,
        );
        $needs = [
            SemanticNeed::fromArray(['canonical_subject' => ['id' => 'subject-1', 'type' => 'model'], 'facet_key' => 'configuration', 'concept_key' => 'layout', 'scope' => 'model']),
            SemanticNeed::fromArray(['canonical_subject' => ['id' => 'subject-1', 'type' => 'model'], 'facet_key' => 'appearance', 'concept_key' => 'surface', 'scope' => 'model']),
        ];

        $first = $engine->retrieveForNeeds(['result_limit' => 20], $needs);
        $second = $engine->retrieveForNeeds(['result_limit' => 20], $needs);
        $diagnostics = $first['retrieval_diagnostics'];

        self::assertSame($diagnostics, $second['retrieval_diagnostics']);
        self::assertSame(2, $diagnostics['need_count']);
        self::assertArrayHasKey('initial_candidate_count', $diagnostics);
        self::assertArrayHasKey('allocation_count', $diagnostics);
        self::assertArrayHasKey('expansion_round_count', $diagnostics);
        self::assertArrayHasKey('coverage_counts', $diagnostics);
        self::assertArrayHasKey('stop_reasons', $diagnostics);
        self::assertSame(20, $diagnostics['selected_count']);
        self::assertLessThanOrEqual(200, $diagnostics['allocation_count']);
        self::assertStringNotContainsString('raw_secret', json_encode($diagnostics, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('private_source_body', json_encode($diagnostics, JSON_UNESCAPED_UNICODE));
    }
}
