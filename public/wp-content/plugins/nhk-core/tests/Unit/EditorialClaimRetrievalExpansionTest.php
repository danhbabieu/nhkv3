<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\ClaimRetrievalEngine;
use PHPUnit\Framework\TestCase;

final class EditorialClaimRetrievalExpansionTest extends TestCase
{
    private const SUBJECT = '33333333-3333-4333-8333-333333333333';

    public function test_sufficient_initial_pool_does_not_expand(): void
    {
        $calls = 0;
        $engine = $this->engine(static function () use (&$calls): array { $calls++; return []; });
        $result = $engine->retrieve($this->context(['coverage_gaps' => []]));

        self::assertSame(0, $calls);
        self::assertSame('coverage_sufficient', $result['retrieval_diagnostics']['stop_reason']);
        self::assertSame([], $result['retrieval_diagnostics']['rounds']);
    }

    public function test_uncovered_gap_uses_one_bounded_expansion_round(): void
    {
        $calls = 0;
        $engine = $this->engine(static function (array $subject, array $reason) use (&$calls): array {
            $calls++;
            return [['id' => 'neighbor', 'subject_id' => 'movement-1', 'subject_type' => 'movement', 'text' => 'Subject mechanism is documented.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE', 'relation_path' => [['source' => 'model:' . self::SUBJECT, 'predicate' => 'uses_movement', 'target' => 'movement:movement-1']]]];
        });
        $result = $engine->retrieve($this->context(['coverage_gaps' => ['mechanism'], 'max_expansion_rounds' => 1]));

        self::assertSame(1, $calls);
        self::assertSame('coverage_saturated', $result['retrieval_diagnostics']['stop_reason']);
        self::assertSame('mechanism', $result['retrieval_diagnostics']['rounds'][0]['reason']);
        self::assertSame(1, $result['retrieval_diagnostics']['rounds'][0]['candidates_considered']);
    }

    public function test_expansion_budget_stops_without_recursive_graph_dump(): void
    {
        $calls = 0;
        $engine = $this->engine(static function () use (&$calls): array { $calls++; return array_fill(0, 1000, ['id' => 'neighbor', 'subject_id' => 'movement-1', 'subject_type' => 'movement', 'text' => 'Subject mechanism is documented.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE']); });
        $result = $engine->retrieve($this->context(['coverage_gaps' => ['mechanism'], 'max_expansion_rounds' => 1, 'expansion_budget' => 3]));

        self::assertSame(1, $calls);
        self::assertSame('expansion_budget', $result['retrieval_diagnostics']['stop_reason']);
        self::assertLessThanOrEqual(3, $result['retrieval_diagnostics']['rounds'][0]['candidates_considered']);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function context(array $extra = []): array
    {
        return array_replace(['subject_resolution' => ['subjects' => [['id' => self::SUBJECT, 'type' => 'model']]], 'raw_input' => 'Subject mechanism', 'result_limit' => 50], $extra);
    }

    private function engine(callable $expansion): ClaimRetrievalEngine
    {
        return new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => [], 2, 50, null, $expansion);
    }
}
