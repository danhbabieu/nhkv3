<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\EditorialKnowledgeSelector;
use PHPUnit\Framework\TestCase;

final class EditorialKnowledgeSelectorAdaptiveTest extends TestCase
{
    private const SUBJECT = '22222222-2222-4222-8222-222222222222';

    public function test_zero_one_and_three_useful_claims_are_not_padded(): void
    {
        $selector = new EditorialKnowledgeSelector();
        $empty = $selector->select($this->retrieval([]), 'subject overview', $this->subject(), ['profile' => 'video']);
        $one = $selector->select($this->retrieval([$this->claim('one', 'Cấu hình máy có ba vách.', 'configuration')]), 'cấu hình máy', $this->subject(), ['profile' => 'video']);
        $three = $selector->select($this->retrieval([
            $this->claim('one', 'Cấu hình máy có ba vách.', 'configuration'),
            $this->claim('two', 'Mặt số có dấu hiệu nhận biết riêng.', 'appearance'),
            $this->claim('three', 'Âm thanh có sắc thái trầm.', 'sound'),
        ]), 'cấu hình và nhận biết', $this->subject(), ['profile' => 'article']);

        self::assertSame('THIN', $empty->diagnostics['coverage_status']);
        self::assertSame([], $empty->selectedClaims);
        self::assertCount(1, $one->selectedClaims);
        self::assertCount(3, $three->selectedClaims);
        self::assertSame(3, $three->diagnostics['selected_unit_count']);
    }

    public function test_provenance_and_duplicate_candidates_do_not_consume_reader_selection(): void
    {
        $retrieval = $this->retrieval([
            $this->claim('provenance', 'The source identifies this video as concerning the subject.', 'identity', ['claim_type' => 'provenance', 'provenance' => 'EXTERNAL_RESEARCH']),
            $this->claim('a', 'Subject có cấu hình máy ba vách.', 'configuration'),
            $this->claim('b', 'Subject có cấu hình máy ba vách!', 'configuration'),
            $this->claim('c', 'Subject có âm thanh trầm.', 'sound'),
        ]);

        $pack = (new EditorialKnowledgeSelector())->select($retrieval, 'cấu hình và âm thanh', $this->subject(), ['profile' => 'video']);

        self::assertSame(['a', 'c'], array_column($pack->selectedClaims, 'claim_id'));
        self::assertSame(['provenance'], array_column($pack->grounding, 'claim_id'));
        self::assertSame(2, $pack->diagnostics['knowledge_unit_count']);
        self::assertSame(2, $pack->diagnostics['selected_unit_count']);
        self::assertNotContains('provenance', array_column($pack->selectedClaims, 'claim_id'));
    }

    public function test_rich_pool_is_bounded_and_stops_after_coverage_saturation(): void
    {
        $candidates = [];
        for ($i = 0; $i < 1000; $i++) {
            $facet = ['configuration', 'appearance', 'sound'][$i % 3];
            $candidates[] = $this->claim('claim-' . $i, 'Subject ' . $facet . ' fact ' . ($i % 9), $facet);
        }

        $pack = (new EditorialKnowledgeSelector())->select($this->retrieval($candidates), 'configuration appearance sound', $this->subject(), ['profile' => 'video']);

        self::assertLessThanOrEqual(3, $pack->diagnostics['selected_unit_count']);
        self::assertLessThanOrEqual(1000, $pack->diagnostics['candidate_count']);
        self::assertContains($pack->diagnostics['stop_reason'], ['coverage_sufficient', 'marginal_gain_low', 'context_budget']);
        self::assertLessThanOrEqual($pack->diagnostics['context_budget'], $pack->diagnostics['context_budget_used']);
    }

    public function test_article_and_video_share_semantics_but_use_surface_budget(): void
    {
        $retrieval = $this->retrieval([
            $this->claim('a', 'Subject có cấu hình máy ba vách.', 'configuration'),
            $this->claim('b', 'Subject có mặt số đặc biệt.', 'appearance'),
            $this->claim('c', 'Subject có âm thanh trầm.', 'sound'),
            $this->claim('d', 'Subject có lịch sử riêng.', 'history'),
        ]);

        $article = (new EditorialKnowledgeSelector())->select($retrieval, 'subject overview', $this->subject(), ['profile' => 'article']);
        $video = (new EditorialKnowledgeSelector())->select($retrieval, 'subject overview', $this->subject(), ['profile' => 'video']);

        self::assertSame($article->diagnostics['coverage_status'], $video->diagnostics['coverage_status']);
        self::assertLessThanOrEqual($article->diagnostics['context_budget'], $article->diagnostics['context_budget_used']);
        self::assertLessThanOrEqual($video->diagnostics['context_budget'], $video->diagnostics['context_budget_used']);
        self::assertNotEmpty(array_intersect(array_column($article->selectedClaims, 'claim_id'), array_column($video->selectedClaims, 'claim_id')));
        self::assertLessThanOrEqual(count($article->selectedClaims), count($video->selectedClaims) + 1);
    }

    public function test_relaxed_and_background_candidates_remain_context_and_do_not_claim_exact_coverage(): void
    {
        $claim = $this->claim('context', 'Broader family context.', 'family', [
            'retrieval_tier' => 'BACKGROUND_CONTEXT',
            'coverage_kind' => 'contextual',
        ]);

        $pack = (new EditorialKnowledgeSelector())->select($this->retrieval([$claim]), 'subject overview', $this->subject(), ['profile' => 'article']);

        self::assertCount(1, $pack->selectedClaims);
        self::assertSame('SUPPORTING_CONTEXT', $pack->selectedClaims[0]['editorial_role']);
        self::assertSame('contextual', $pack->selectedClaims[0]['coverage_kind']);
        self::assertNotSame('SUFFICIENT', $pack->diagnostics['coverage_status']);
        self::assertContains('contextual', $pack->diagnostics['coverage_kinds']);
    }

    public function test_exact_candidate_without_new_coverage_is_rejected_instead_of_getting_synthetic_context_gain(): void
    {
        $pack = (new EditorialKnowledgeSelector())->select($this->retrieval([
            $this->claim('configuration-a', 'Subject có cấu hình máy ba vách.', 'configuration'),
            $this->claim('configuration-b', 'Subject có cấu hình máy bốn vách.', 'configuration'),
            $this->claim('appearance', 'Subject có mặt số đặc biệt.', 'appearance'),
        ]), 'cấu hình và mặt số', $this->subject(), ['profile' => 'article']);

        self::assertSame(['configuration-a', 'appearance'], array_column($pack->selectedClaims, 'claim_id'));
        $excluded = array_values(array_filter($pack->excludedCandidates, static fn (array $claim): bool => ($claim['claim_id'] ?? '') === 'configuration-b'));
        self::assertCount(1, $excluded);
        self::assertContains('LOW_MARGINAL_INFORMATION_GAIN', $excluded[0]['exclusion_reasons']);
    }

    /** @param list<array<string,mixed>> $claims @return array<string,mixed> */
    private function retrieval(array $claims): array { return ['status' => 'available', 'items' => $claims, 'eligible_claims' => $claims]; }

    /** @return array<string,mixed> */
    private function subject(): array { return ['id' => self::SUBJECT, 'type' => 'model', 'name' => 'Subject']; }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function claim(string $id, string $text, string $facet, array $extra = []): array
    {
        return array_replace([
            'claim_id' => $id,
            'claim_revision' => 1,
            'text' => $text,
            'eligibility' => 'eligible',
            'subject_id' => self::SUBJECT,
            'subject_type' => 'model',
            'original_subject' => ['id' => self::SUBJECT, 'type' => 'model'],
            'resolved_primary_subject' => ['id' => self::SUBJECT, 'type' => 'model'],
            'scope' => 'model',
            'facet' => $facet,
            'evidence' => ['status' => 'eligible'],
            'provenance_references' => ['source_ids' => ['source-1'], 'evidence_ids' => ['evidence-1']],
            'source_ids' => ['source-1'],
            'evidence_ids' => ['evidence-1'],
        ], $extra);
    }
}
