<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{EditorialContextPack, EditorialDraft, EditorialPlan, EditorialQualityGate, EditorialQualityReport, SemanticSeoPlan};
use PHPUnit\Framework\TestCase;

final class EditorialQualityGateTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';

    public function test_good_odo_package_is_ready_without_an_opaque_score(): void
    {
        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $this->seo());

        self::assertInstanceOf(EditorialQualityReport::class, $report);
        self::assertSame('INCOMPLETE', $report->readiness);
        self::assertSame('article', $report->profile);
        self::assertSame('READY', $report->dimensions['factual_grounding']['status']);
        self::assertSame('READY', $report->dimensions['seo_readiness']['status']);
        self::assertArrayNotHasKey('score', $report->toArray());
    }

    public function test_h1_mechanical_composition_is_incomplete_with_explainable_warnings(): void
    {
        $draft = new EditorialDraft('available', 'video', $this->topic(), 'Đây là 3 phiên bản máy của dòng đồng hồ Odo 36 Video là điểm bắt đầu để theo dõi.', "Đây là 3 phiên bản máy của dòng đồng hồ Odo 36 Video là điểm bắt đầu để theo dõi.\n\nĐiểm chính của chủ đề: Odo 36 có ba phiên bản vách máy.\n\nBối cảnh hữu ích: Vách xoáy giúp nhận biết cấu hình máy Odo 36.", [
            ['claim_id' => 'core-1', 'claim_revision' => 2],
        ], ['source_input' => 'Đây là 3 phiên bản máy của dòng đồng hồ Odo 36 Video là điểm bắt đầu để theo dõi.']);

        $report = $this->gate()->evaluate($this->pack($this->claims(), [], 'video'), $this->plan('video'), $draft, $this->seo('video'));

        self::assertNotSame('READY', $report->readiness);
        self::assertContains('VISIBLE_PLANNING_LABEL', $report->warnings);
        self::assertContains('MALFORMED_SENTENCE_JOIN', $report->warnings);
        self::assertContains('GENERIC_EDITORIAL_FILLER', $report->warnings);
    }

    public function test_stale_revision_and_ineligible_claim_usage_block_public_readiness(): void
    {
        $claims = $this->claims();
        $claims[1]['eligibility'] = 'ineligible';
        $draft = new EditorialDraft('available', 'article', 'Odo 36', 'Odo 36 có ba phiên bản.', 'Odo 36 có ba phiên bản. Vách xoáy giải thích cấu hình.', [
            ['claim_id' => 'core-1', 'claim_revision' => 1],
            ['claim_id' => 'explain-1', 'claim_revision' => 99],
        ]);

        $report = $this->gate()->evaluate($this->pack($claims), $this->plan(), $draft, $this->seo());

        self::assertSame('BLOCKED', $report->readiness);
        self::assertContains('STALE_CLAIM_REVISION', $report->blockers);
        self::assertContains('INELIGIBLE_CLAIM_USED', $report->blockers);
        self::assertSame('BLOCK', $report->dimensions['traceability']['severity']);
    }

    public function test_underused_knowledge_and_buried_core_are_warned(): void
    {
        $plan = new EditorialPlan('available', 'article', ['id' => self::SUBJECT, 'type' => 'model'], $this->topic(), [
            ['id' => 'opening', 'title' => 'Mở đầu', 'claims' => []],
            ['id' => 'context-1', 'title' => 'Bối cảnh', 'claims' => []],
            ['id' => 'context-2', 'title' => 'Chi tiết', 'claims' => []],
            ['id' => 'core', 'title' => 'Điểm chính', 'claims' => []],
        ]);
        $draft = new EditorialDraft('available', 'article', $this->topic(), 'Một mô tả ngắn.', 'Một mô tả ngắn.', [['claim_id' => 'explain-1', 'claim_revision' => 1]], ['information_gain' => 0.02]);

        $report = $this->gate()->evaluate($this->pack(), $plan, $draft, $this->seo());

        self::assertSame('INCOMPLETE', $report->readiness);
        self::assertContains('UNDERUTILIZED_RELEVANT_KNOWLEDGE', $report->warnings);
        self::assertContains('CORE_TOPIC_BURIED', $report->warnings);
        self::assertContains('LOW_INFORMATION_GAIN', $report->warnings);
    }

    public function test_long_paraphrase_without_new_information_is_not_accepted_as_gain(): void
    {
        $draft = new EditorialDraft('available', 'article', $this->topic(), 'Odo 36 có ba phiên bản.', implode("\n\n", array_fill(0, 12, 'Odo 36 có ba phiên bản.')), [
            ['claim_id' => 'core-1', 'claim_revision' => 2],
        ], ['information_gain' => 0.01, 'mode' => 'selected_knowledge']);

        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $draft, $this->seo());

        self::assertContains('LOW_INFORMATION_GAIN', $report->warnings);
        self::assertContains('EXCESSIVE_REDUNDANCY', $report->warnings);
        self::assertSame('WARN', $report->dimensions['information_gain']['severity']);
    }

    public function test_internal_language_and_unsupported_promotion_are_blocked(): void
    {
        $draft = new EditorialDraft('available', 'article', 'Odo 36', 'Knowledge stable key', 'Odo 36 là số 1 và có canonical UUID abc.', [['claim_id' => 'core-1', 'claim_revision' => 2]]);

        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $draft, $this->seo());

        self::assertSame('BLOCKED', $report->readiness);
        self::assertContains('PUBLIC_INTERNAL_JARGON_LEAK', $report->blockers);
        self::assertContains('UNSUPPORTED_PROMOTIONAL_CLAIM', $report->blockers);
    }

    public function test_unresolved_visual_support_and_representative_only_media_do_not_pass(): void
    {
        $pack = $this->pack($this->claims(), [['role' => 'featured', 'status' => 'UNRESOLVED', 'support' => 'representative']]);
        $plan = new EditorialPlan('available', 'image', ['id' => self::SUBJECT, 'type' => 'model'], $this->topic(), [['id' => 'opening', 'claims' => []]], [], $pack->visualSupport);
        $draft = $this->draft('image');
        $seo = $this->seo('image');

        $report = $this->gate()->evaluate($pack, $plan, $draft, $seo);

        self::assertSame('BLOCKED', $report->readiness);
        self::assertContains('VISUAL_SUPPORT_UNRESOLVED', $report->blockers);
        self::assertSame('BLOCK', $report->dimensions['visual_support']['severity']);
    }

    public function test_invalid_internal_link_and_seo_mismatch_block_readiness(): void
    {
        $seo = new SemanticSeoPlan('INCOMPLETE', 'article', 'wrong-intent', ['id' => self::SUBJECT, 'type' => 'model'], 'Khác chủ đề', [], 'Khác', 'Khác', 'Khác', '/4cbe5aa1-4222-46bd-a140-6ab66d2da199/', [], [
            ['destination_id' => 'private', 'url' => 'https://private.example/uuid'],
        ]);

        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $seo);

        self::assertSame('BLOCKED', $report->readiness);
        self::assertContains('SEO_NOT_READY', $report->blockers);
        self::assertContains('INVALID_PUBLIC_INTERNAL_LINK', $report->blockers);
        self::assertContains('SEO_TOPIC_MISMATCH', $report->warnings);
    }

    public function test_profile_fit_and_sparse_input_are_reported_without_inventing_facts(): void
    {
        $pack = $this->pack([], [], 'video');
        $plan = new EditorialPlan('available', 'video', ['id' => self::SUBJECT, 'type' => 'model'], $this->topic(), [['id' => 'opening', 'claims' => []]], ['raw_input' => 'Odo 36'], []);
        $draft = new EditorialDraft('sparse_input', 'video', 'Odo 36', 'Odo 36', 'Odo 36', [], ['information_gain' => 0.0]);
        $seo = $this->seo('video');

        $report = $this->gate()->evaluate($pack, $plan, $draft, $seo);

        self::assertContains('SPARSE_KNOWLEDGE_INPUT', $report->informational);
        self::assertSame('WARN', $report->dimensions['profile_fit']['severity']);
        self::assertSame('INCOMPLETE', $report->readiness);
    }

    public function test_untraceable_factual_assertion_blocks_grounding(): void
    {
        $draft = new EditorialDraft('available', 'article', $this->topic(), 'Odo 36 có ba phiên bản vách máy.', 'Odo 36 có ba phiên bản vách máy. Vách hở được sản xuất năm 1945.', [
            ['claim_id' => 'core-1', 'claim_revision' => 2],
        ]);

        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $draft, $this->seo());

        self::assertContains('UNTRACEABLE_FACTUAL_ASSERTION', $report->blockers);
        self::assertSame('BLOCK', $report->dimensions['factual_grounding']['severity']);
    }

    public function test_scope_mismatch_in_trace_blocks_even_when_revision_matches(): void
    {
        $claims = $this->claims();
        $claims[1]['original_subject'] = ['id' => 'movement-1', 'type' => 'movement'];
        $draft = new EditorialDraft('available', 'article', $this->topic(), 'Odo 36 có ba phiên bản.', 'Odo 36 có ba phiên bản. Vách xoáy thuộc toàn bộ thương hiệu.', [
            ['claim_id' => 'explain-1', 'claim_revision' => 1, 'original_subject' => ['id' => self::SUBJECT, 'type' => 'model']],
        ]);

        $report = $this->gate()->evaluate($this->pack($claims), $this->plan(), $draft, $this->seo());

        self::assertContains('EDITORIAL_SCOPE_WIDENED', $report->blockers);
    }

    public function test_system_language_patterns_are_blocked_even_when_copy_guard_does_not_match(): void
    {
        $draft = new EditorialDraft('available', 'article', 'Odo 36', 'Theo semantic graph', 'Theo semantic graph, claim hiện có cho thấy Odo 36 có cấu hình phù hợp.', [['claim_id' => 'core-1', 'claim_revision' => 2]]);

        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $draft, $this->seo());

        self::assertContains('PUBLIC_INTERNAL_JARGON_LEAK', $report->blockers);
    }

    public function test_exact_feature_support_is_accepted_but_representative_support_is_not(): void
    {
        $exact = $this->pack($this->claims(), [[
            'status' => 'RESOLVED', 'support' => 'feature', 'feature_key' => 'wall_plate_swirl',
            'subject_id' => self::SUBJECT, 'scope' => 'model', 'media_id' => 'media-feature-1',
            'public_eligible' => true, 'exact_scope' => true,
        ]], 'image');
        $exactReport = $this->gate()->evaluate($exact, $this->plan('image'), $this->draft('image'), $this->seo('image'));
        self::assertNotContains('VISUAL_SUPPORT_UNRESOLVED', $exactReport->blockers);

        $representative = $this->pack($this->claims(), [[
            'status' => 'RESOLVED', 'support' => 'representative', 'feature_key' => 'wall_plate_swirl',
            'subject_id' => self::SUBJECT, 'scope' => 'model', 'public_eligible' => true,
        ]], 'image');
        $representativeReport = $this->gate()->evaluate($representative, $this->plan('image'), $this->draft('image'), $this->seo('image'));
        self::assertContains('VISUAL_SUPPORT_UNRESOLVED', $representativeReport->blockers);
    }

    public function test_visual_support_not_required_is_not_treated_as_media_failure(): void
    {
        $pack = $this->pack($this->claims(), [['status' => 'NOT_REQUIRED', 'support' => 'not_required']], 'article');
        $report = $this->gate()->evaluate($pack, $this->plan(), $this->draft(), $this->seo());

        self::assertNotContains('VISUAL_SUPPORT_UNRESOLVED', $report->blockers);
        self::assertSame('READY', $report->dimensions['visual_support']['status']);
    }

    public function test_irrelevant_expansion_warns_without_becoming_a_blocking_truth_failure(): void
    {
        $draft = new EditorialDraft('available', 'article', $this->topic(), 'Odo 36 có ba phiên bản vách máy.', 'Odo 36 có ba phiên bản vách máy. Kích thước vỏ là 42 mm và dây đeo dài 20 cm.', [['claim_id' => 'core-1', 'claim_revision' => 2]], ['information_gain' => 0.7]);

        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $draft, $this->seo());

        self::assertContains('TOPIC_DRIFT', $report->warnings);
        self::assertNotContains('TOPIC_DRIFT', $report->blockers);
    }

    public function test_video_profile_requires_video_spine_but_does_not_require_generic_encyclopedia_language(): void
    {
        $draft = new EditorialDraft('available', 'video', 'Odo 36', 'Một mô tả', 'Lịch sử đồng hồ nói chung và nhiều thông tin ngoài video.', [['claim_id' => 'core-1', 'claim_revision' => 2]], ['information_gain' => 0.7]);

        $report = $this->gate()->evaluate($this->pack($this->claims(), [], 'video'), $this->plan('video'), $draft, $this->seo('video'));

        self::assertContains('VIDEO_TOPIC_SPINE_WEAK', $report->warnings);
    }

    public function test_link_with_valid_url_but_irrelevant_target_warns(): void
    {
        $seo = new SemanticSeoPlan('READY', 'article', 'overview', ['id' => self::SUBJECT, 'type' => 'model'], $this->topic(), ['Odo 36'], $this->topic(), $this->topic(), 'Tìm hiểu ba phiên bản vách máy của đồng hồ Odo 36.', '/mau/odo36/', [], [['destination_id' => 'other', 'url' => '/bo-may/khac/', 'title' => 'Lịch sử dây đeo']], [], [], [], []);

        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $seo);

        self::assertContains('IRRELEVANT_INTERNAL_LINK', $report->warnings);
    }

    public function test_ineligible_evidence_blocks_selected_claim(): void
    {
        $claims = $this->claims();
        $claims[0]['evidence'] = ['status' => 'ineligible'];
        $report = $this->gate()->evaluate($this->pack($claims), $this->plan(), $this->draft(), $this->seo());

        self::assertContains('CLAIM_EVIDENCE_NOT_ELIGIBLE', $report->blockers);
    }

    public function test_optional_context_claim_can_remain_unused_with_warning_only(): void
    {
        $claims = [['claim_id' => 'context-1', 'claim_revision' => 1, 'text' => 'Một bối cảnh liên quan.', 'eligibility' => 'eligible', 'editorial_role' => 'CONTEXT', 'original_subject' => ['id' => self::SUBJECT, 'type' => 'model']]];
        $draft = new EditorialDraft('sparse_input', 'article', $this->topic(), 'Một mô tả ngắn.', 'Một mô tả ngắn.', [], ['information_gain' => 0.0]);
        $report = $this->gate()->evaluate($this->pack($claims), $this->plan(), $draft, $this->seo());

        self::assertContains('UNDERUTILIZED_RELEVANT_KNOWLEDGE', $report->warnings);
        self::assertNotContains('INELIGIBLE_CLAIM_USED', $report->blockers);
    }

    public function test_coherent_reader_journey_is_not_buried(): void
    {
        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $this->seo());

        self::assertNotContains('CORE_TOPIC_BURIED', $report->warnings);
        self::assertSame('READY', $report->dimensions['reader_journey']['status']);
    }

    public function test_repeated_stock_sections_trigger_boilerplate_warning(): void
    {
        $body = "Video là điểm bắt đầu để tìm hiểu.\n\nVideo là điểm bắt đầu để tìm hiểu.";
        $draft = new EditorialDraft('available', 'video', $this->topic(), 'Odo 36', $body, [['claim_id' => 'core-1', 'claim_revision' => 2]], ['information_gain' => 0.7]);
        $report = $this->gate()->evaluate($this->pack($this->claims(), [], 'video'), $this->plan('video'), $draft, $this->seo('video'));

        self::assertContains('TEMPLATE_BOILERPLATE_EXPOSED', $report->warnings);
    }

    public function test_uuid_leakage_is_blocked(): void
    {
        $draft = new EditorialDraft('available', 'article', $this->topic(), 'Odo 36', 'Bản ghi 4cbe5aa1-4222-46bd-a140-6ab66d2da199 được chọn.', [['claim_id' => 'core-1', 'claim_revision' => 2]]);
        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $draft, $this->seo());

        self::assertContains('PUBLIC_INTERNAL_JARGON_LEAK', $report->blockers);
    }

    public function test_media_unavailable_visual_support_is_not_silently_ready(): void
    {
        $pack = $this->pack($this->claims(), [['status' => 'UNAVAILABLE', 'support' => 'feature', 'required' => true]], 'image');
        $report = $this->gate()->evaluate($pack, $this->plan('image'), $this->draft('image'), $this->seo('image'));

        self::assertContains('VISUAL_SUPPORT_UNRESOLVED', $report->blockers);
    }

    public function test_not_applicable_seo_readiness_is_allowed(): void
    {
        $seo = new SemanticSeoPlan('NOT_APPLICABLE', 'article', 'overview', ['id' => self::SUBJECT, 'type' => 'model'], $this->topic(), [], $this->topic(), $this->topic(), '', null, []);
        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $seo);

        self::assertNotContains('SEO_NOT_READY', $report->blockers);
    }

    public function test_unavailable_seo_blocks_readiness(): void
    {
        $seo = new SemanticSeoPlan('UNAVAILABLE', 'article', 'overview', ['id' => self::SUBJECT, 'type' => 'model'], $this->topic(), [], $this->topic(), $this->topic(), '', null, []);
        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $seo);

        self::assertContains('SEO_NOT_READY', $report->blockers);
    }

    public function test_missing_internal_link_url_blocks(): void
    {
        $seo = new SemanticSeoPlan('READY', 'article', 'overview', ['id' => self::SUBJECT, 'type' => 'model'], $this->topic(), [], $this->topic(), $this->topic(), $this->topic(), '/mau/odo36/', [], [['destination_id' => 'x']]);
        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $seo);

        self::assertContains('INVALID_PUBLIC_INTERNAL_LINK', $report->blockers);
    }

    public function test_article_profile_fit_is_ready_for_article_copy(): void
    {
        $report = $this->gate()->evaluate($this->pack(), $this->plan('article'), $this->draft('article'), $this->seo('article'));

        self::assertSame('READY', $report->dimensions['profile_fit']['status']);
    }

    public function test_image_profile_missing_image_marker_is_warned(): void
    {
        $draft = new EditorialDraft('available', 'image', $this->topic(), 'Odo 36', 'Odo 36 có ba phiên bản vách máy.', [['claim_id' => 'core-1', 'claim_revision' => 2]], ['information_gain' => 0.7]);
        $report = $this->gate()->evaluate($this->pack($this->claims(), [], 'image'), $this->plan('image'), $draft, $this->seo('image'));

        self::assertContains('IMAGE_PROFILE_MARKER_MISSING', $report->warnings);
    }

    public function test_missing_claim_trace_is_blocked(): void
    {
        $draft = new EditorialDraft('available', 'article', $this->topic(), 'Odo 36', 'Odo 36 có ba phiên bản vách máy.', [], ['information_gain' => 0.7]);
        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $draft, $this->seo());

        self::assertContains('MISSING_CLAIM_TRACE', $report->blockers);
    }

    public function test_report_is_deterministic_for_identical_inputs(): void
    {
        $first = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $this->seo())->toArray();
        $second = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $this->seo())->toArray();

        self::assertSame($first, $second);
    }

    public function test_report_diagnostics_do_not_include_an_opaque_quality_score(): void
    {
        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $this->seo());

        self::assertFalse($report->diagnostics['opaque_score']);
        self::assertArrayNotHasKey('score', $report->diagnostics);
    }

    public function test_claim_trace_can_carry_original_subject_metadata(): void
    {
        $draft = new EditorialDraft('available', 'article', $this->topic(), 'Odo 36', 'Odo 36 có ba phiên bản vách máy. Vách xoáy giúp nhận biết cấu hình máy Odo 36.', [
            ['claim_id' => 'core-1', 'claim_revision' => 2, 'original_subject' => ['id' => self::SUBJECT, 'type' => 'model']],
            ['claim_id' => 'explain-1', 'claim_revision' => 1, 'original_subject' => ['id' => 'movement-1', 'type' => 'movement']],
        ], ['information_gain' => 0.7]);
        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $draft, $this->seo());

        self::assertNotContains('EDITORIAL_SCOPE_WIDENED', $report->blockers);
    }

    public function test_selected_claim_without_evidence_status_remains_compatible_with_legacy_read_model(): void
    {
        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $this->seo());

        self::assertNotContains('CLAIM_EVIDENCE_NOT_ELIGIBLE', $report->blockers);
    }

    public function test_readiness_is_explainable_by_blockers_and_warnings(): void
    {
        $report = $this->gate()->evaluate($this->pack(), $this->plan(), $this->draft(), $this->seo());

        self::assertSame('INCOMPLETE', $report->readiness);
        self::assertSame([], $report->blockers);
        self::assertContains('ENUMERATION_PROMISE_UNFULFILLED', $report->warnings);
    }

    private function gate(): EditorialQualityGate { return new EditorialQualityGate(); }

    private function topic(): string { return '3 phiên bản vách máy của đồng hồ Odo 36'; }

    /** @return list<array<string,mixed>> */
    private function claims(): array
    {
        return [
            ['claim_id' => 'core-1', 'claim_revision' => 2, 'text' => 'Odo 36 có ba phiên bản vách máy.', 'eligibility' => 'eligible', 'editorial_role' => 'CORE', 'original_subject' => ['id' => self::SUBJECT, 'type' => 'model']],
            ['claim_id' => 'explain-1', 'claim_revision' => 1, 'text' => 'Vách xoáy giúp nhận biết cấu hình máy Odo 36.', 'eligibility' => 'eligible', 'editorial_role' => 'EXPLANATION', 'original_subject' => ['id' => 'movement-1', 'type' => 'movement']],
        ];
    }

    private function pack(array $claims = [], array $visual = [], string $profile = 'article'): EditorialContextPack
    {
        return new EditorialContextPack('available', ['id' => self::SUBJECT, 'type' => 'model'], $this->topic(), ['profile' => $profile], 'available', $claims === [] && $profile !== 'video' ? $this->claims() : $claims, [], ['raw_input' => 'Đây là 3 phiên bản máy của dòng đồng hồ Odo 36'], $visual);
    }

    private function plan(string $profile = 'article'): EditorialPlan
    {
        return new EditorialPlan('available', $profile, ['id' => self::SUBJECT, 'type' => 'model'], $this->topic(), [
            ['id' => 'opening', 'title' => 'Mở đầu', 'claims' => []],
            ['id' => 'core', 'title' => 'Điểm chính', 'claims' => $this->claims()],
        ]);
    }

    private function draft(string $profile = 'article'): EditorialDraft
    {
        $body = 'Odo 36 có ba phiên bản vách máy. Vách xoáy giúp nhận biết cấu hình máy Odo 36.';
        return new EditorialDraft('available', $profile, $this->topic(), 'Odo 36 có ba phiên bản vách máy.', $body, [['claim_id' => 'core-1', 'claim_revision' => 2], ['claim_id' => 'explain-1', 'claim_revision' => 1]], ['mode' => 'selected_knowledge', 'information_gain' => 0.7]);
    }

    private function seo(string $profile = 'article'): SemanticSeoPlan
    {
        return new SemanticSeoPlan('READY', $profile, 'overview', ['id' => self::SUBJECT, 'type' => 'model'], $this->topic(), ['Odo 36'], $this->topic(), $this->topic(), 'Tìm hiểu ba phiên bản vách máy của đồng hồ Odo 36.', '/mau/odo36/', [], [['destination_id' => 'movement-1', 'url' => '/bo-may/odo-36/']]);
    }
}
