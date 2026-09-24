<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\EditorialKnowledgeSelector;
use PHPUnit\Framework\TestCase;

final class AdaptiveKnowledgeSelectionPerformanceTest extends TestCase
{
    public function test_thousand_candidates_and_five_hundred_duplicates_remain_bounded_and_deterministic(): void
    {
        $claims = [];
        for ($i = 0; $i < 500; $i++) $claims[] = $this->claim('duplicate-' . $i, 'Subject có cấu hình máy ba vách.', 'configuration');
        for ($i = 0; $i < 500; $i++) $claims[] = $this->claim('unique-' . $i, 'Subject có âm thanh và chi tiết ' . $i . '.', $i % 2 === 0 ? 'sound' : 'appearance');

        $selector = new EditorialKnowledgeSelector();
        $first = $selector->select(['status' => 'available', 'items' => $claims, 'eligible_claims' => $claims], 'cấu hình âm thanh appearance', ['id' => 'subject', 'type' => 'model'], ['profile' => 'article']);
        $second = $selector->select(['status' => 'available', 'items' => $claims, 'eligible_claims' => $claims], 'cấu hình âm thanh appearance', ['id' => 'subject', 'type' => 'model'], ['profile' => 'article']);

        self::assertSame(array_column($first->selectedClaims, 'claim_id'), array_column($second->selectedClaims, 'claim_id'));
        self::assertSame($first->diagnostics['coverage_achieved'], $second->diagnostics['coverage_achieved']);
        self::assertSame(1000, $first->diagnostics['candidate_count']);
        self::assertLessThan(505, $first->diagnostics['knowledge_unit_count']);
        self::assertLessThanOrEqual($first->diagnostics['context_budget'], $first->diagnostics['context_budget_used']);
    }

    /** @return array<string,mixed> */
    private function claim(string $id, string $text, string $facet): array
    {
        return ['claim_id' => $id, 'claim_revision' => 1, 'text' => $text, 'eligibility' => 'eligible', 'subject_id' => 'subject', 'subject_type' => 'model', 'original_subject' => ['id' => 'subject', 'type' => 'model'], 'resolved_primary_subject' => ['id' => 'subject', 'type' => 'model'], 'scope' => 'model', 'facet' => $facet, 'evidence' => ['status' => 'eligible'], 'source_ids' => ['source'], 'evidence_ids' => ['evidence']];
    }
}
