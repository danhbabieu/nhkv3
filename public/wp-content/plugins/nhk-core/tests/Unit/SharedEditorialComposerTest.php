<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{EditorialContextPack, EditorialPlan, EditorialKnowledgeSelector, ReaderJourneyPlanner, SharedEditorialComposer};
use PHPUnit\Framework\TestCase;

final class SharedEditorialComposerTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';

    public function test_typed_editorial_copy_replaces_raw_instruction_in_shared_article_composition(): void
    {
        $pack = $this->pack('article', [], [
            'raw_input' => 'Tạo bài viết về Junghans W64.',
            'editorial_copy' => 'Đây là ghi chú biên tập về chiếc đồng hồ thực tế.',
            'non_semantic_context' => ['instructions' => ['Tạo bài viết về Junghans W64.']],
        ]);

        $draft = (new SharedEditorialComposer())->compose((new ReaderJourneyPlanner())->plan($pack));

        self::assertStringContainsString('Đây là ghi chú biên tập', $draft->body);
        self::assertStringNotContainsString('Tạo bài viết về Junghans W64', $draft->body);
    }

    public function test_context_pack_becomes_deterministic_subject_first_plan_and_traceable_article_draft(): void
    {
        $pack = $this->pack('article', [
            $this->claim('core', 'Odo 36 có ba phiên bản vách máy.', 'CORE', 'direct'),
            $this->claim('explain', 'Vách xoáy giúp nhận biết cấu hình máy Odo 36.', 'EXPLANATION', 'neighborhood'),
        ]);

        $planner = new ReaderJourneyPlanner();
        $plan = $planner->plan($pack);
        $draft = (new SharedEditorialComposer())->compose($plan);

        self::assertInstanceOf(EditorialPlan::class, $plan);
        self::assertSame(['opening', 'core', 'explain'], array_column($plan->sections, 'id'));
        self::assertSame('article', $draft->profile);
        self::assertStringContainsString('vách máy', strtolower($draft->body));
        self::assertSame(['core', 'explain'], array_column($draft->claimTrace, 'claim_id'));
        self::assertSame(1, $draft->claimTrace[0]['claim_revision']);
        self::assertStringNotContainsString(self::SUBJECT, $draft->body);
    }

    public function test_video_and_image_profiles_keep_their_source_central_and_are_not_generic_article_copy(): void
    {
        $claims = [$this->claim('core', 'Vách cam là dấu hiệu nhận biết cấu hình.', 'CORE', 'direct')];
        $video = (new SharedEditorialComposer())->compose((new ReaderJourneyPlanner())->plan($this->pack('video', $claims, ['raw_input' => 'Video giới thiệu Odo 36.'])));
        $image = (new SharedEditorialComposer())->compose((new ReaderJourneyPlanner())->plan($this->pack('image', $claims, ['raw_input' => 'Hình ảnh mặt sau đồng hồ Odo 36.'])));

        self::assertStringContainsString('Video giới thiệu Odo 36', $video->body);
        self::assertStringContainsString('Hình ảnh mặt sau đồng hồ Odo 36', $image->body);
        self::assertNotSame($video->body, $image->body);
        self::assertSame(['core'], array_column($video->claimTrace, 'claim_id'));
        self::assertSame(['core'], array_column($image->claimTrace, 'claim_id'));
    }

    public function test_sparse_input_without_knowledge_remains_source_grounded_without_fabricated_enrichment(): void
    {
        $pack = $this->pack('article', [], ['raw_input' => 'Đây là 3 phiên bản máy của dòng đồng hồ Odo 36.']);
        $draft = (new SharedEditorialComposer())->compose((new ReaderJourneyPlanner())->plan($pack));

        self::assertSame([], $draft->claimTrace);
        self::assertStringContainsString('3 phiên bản máy', $draft->body);
        self::assertStringNotContainsString('vách xoáy', $draft->body);
        self::assertSame('sparse_input', $draft->diagnostics['mode']);
    }

    public function test_public_draft_has_no_internal_jargon_and_visual_support_remains_unresolved(): void
    {
        $claim = $this->claim('visual', 'Vách cam giúp nhận biết cấu hình.', 'CORE', 'direct');
        $pack = $this->pack('image', [$claim], ['raw_input' => 'Hình ảnh vách máy Odo 36.'], [['claim_id' => 'visual', 'status' => 'UNRESOLVED', 'reason' => 'FEATURE_SUPPORT_REQUIRED']]);
        $draft = (new SharedEditorialComposer())->compose((new ReaderJourneyPlanner())->plan($pack));

        foreach (['canonical UUID', 'stable key', 'Graph', 'Governance', 'MCP', 'provenance', 'Evidence', 'claim revision'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $draft->body);
        }
        self::assertSame('UNRESOLVED', $draft->diagnostics['visual_support'][0]['status']);
    }

    public function test_irrelevant_excluded_claim_never_enters_plan_or_draft(): void
    {
        $pack = $this->pack('article', [$this->claim('core', 'Vách xoáy giúp nhận biết cấu hình.', 'CORE', 'direct')]);
        $pack = new EditorialContextPack($pack->status, $pack->primarySubject, $pack->topic, $pack->profile, $pack->retrievalStatus, $pack->selectedClaims, [$this->claim('irrelevant', 'Kích thước tủ không liên quan.', 'CONTEXT', 'direct', 'ineligible', ['TOPIC_IRRELEVANT'])], $pack->inputContext, $pack->visualSupport, $pack->blockers, $pack->diagnostics);
        $plan = (new ReaderJourneyPlanner())->plan($pack);
        $draft = (new SharedEditorialComposer())->compose($plan);

        self::assertNotContains('irrelevant', array_column(array_merge(...array_map(static fn (array $section): array => $section['claim_refs'], $plan->sections)), 'claim_id'));
        self::assertStringNotContainsString('Kích thước tủ', $draft->body);
    }

    public function test_h2_keeps_planning_roles_internal_and_removes_profile_filler(): void
    {
        $plan = (new ReaderJourneyPlanner())->plan($this->pack('video', [
            $this->claim('core', 'Một dấu hiệu dễ nhận ra là vách cam.', 'IDENTIFICATION', 'direct'),
            $this->claim('explain', 'Cấu hình này có ba phiên bản.', 'EXPLANATION', 'direct'),
        ], ['raw_input' => 'Video giới thiệu mẫu đồng hồ A.']));

        $draft = (new SharedEditorialComposer())->compose($plan);

        self::assertStringNotContainsString('Điểm chính của chủ đề:', $draft->body);
        self::assertStringNotContainsString('Điều cần hiểu thêm:', $draft->body);
        self::assertStringNotContainsString('Video là điểm bắt đầu', $draft->body);
        self::assertStringContainsString('Video giới thiệu mẫu đồng hồ A.', $draft->body);
        self::assertStringContainsString('vách cam', $draft->body);
        self::assertSame(['core', 'explain'], array_column($draft->claimTrace, 'claim_id'));
    }

    public function test_h2_normalizes_source_punctuation_and_joins_claims_deterministically(): void
    {
        $pack = $this->pack('video', [
            $this->claim('core', 'Mẫu A có vách cam.', 'CORE', 'direct'),
            $this->claim('second', 'Vách này giúp nhận biết cấu hình.', 'IDENTIFICATION', 'direct'),
        ], ['raw_input' => 'Video giới thiệu mẫu A...']);
        $planner = new ReaderJourneyPlanner();
        $composer = new SharedEditorialComposer();

        $first = $composer->compose($planner->plan($pack));
        $second = $composer->compose($planner->plan($pack));

        self::assertSame($first->body, $second->body);
        self::assertStringNotContainsString('... ', $first->body);
        self::assertStringNotContainsString('. Mẫu', $first->body);
        self::assertStringNotContainsString('A... ', $first->body);
        self::assertStringContainsString('Mẫu A có vách cam.', $first->body);
    }

    public function test_h2_combines_only_contiguous_related_claims_and_retains_all_traces(): void
    {
        $claims = [
            $this->claim('a', 'Mẫu A có vách cam.', 'CORE', 'direct'),
            $this->claim('b', 'Mẫu A có vách xanh.', 'CORE', 'direct'),
            $this->claim('c', 'Âm nhạc của bộ sưu tập được ghi nhận riêng.', 'CONTEXT', 'direct'),
        ];
        $draft = (new SharedEditorialComposer())->compose((new ReaderJourneyPlanner())->plan($this->pack('article', $claims)));

        self::assertCount(3, $draft->claimTrace);
        self::assertStringContainsString('Mẫu A có vách cam.', $draft->body);
        self::assertStringContainsString('Mẫu A có vách xanh.', $draft->body);
        self::assertStringContainsString('âm nhạc của bộ sưu tập được ghi nhận riêng.', $draft->body);
        self::assertStringNotContainsString('Mẫu A có vách cam. Mẫu A có vách xanh.', $draft->body);
    }

    public function test_raw_plan_cannot_bypass_public_composability_or_applicability(): void
    {
        $plan = new EditorialPlan('available', 'article', ['id' => self::SUBJECT, 'type' => 'model'], 'Odo 36', [
            ['id' => 'opening', 'claims' => [], 'claim_refs' => []],
            ['id' => 'core', 'claims' => [
                ['claim_id' => 'private', 'claim_revision' => 1, 'text' => 'Không được đưa vào bài.', 'eligibility' => 'eligible', 'publicly_composable' => false, 'applicability' => 'applicable'],
                ['claim_id' => 'broad', 'claim_revision' => 1, 'text' => 'Không được mở rộng phạm vi.', 'eligibility' => 'eligible', 'publicly_composable' => true, 'applicability' => 'inapplicable'],
            ], 'claim_refs' => []],
        ], ['raw_input' => 'Mô tả đầu vào của Odo 36.']);

        $draft = (new SharedEditorialComposer())->compose($plan);

        self::assertSame([], $draft->claimTrace);
        self::assertStringNotContainsString('Không được đưa vào bài', $draft->body);
        self::assertStringNotContainsString('Không được mở rộng phạm vi', $draft->body);
        self::assertSame('review', $draft->status);
    }

    private function pack(string $profile, array $claims, array $input = [], array $visual = []): EditorialContextPack
    {
        return new EditorialContextPack('available', ['id' => self::SUBJECT, 'type' => 'model'], '3 phiên bản vách máy Odo 36', ['profile' => $profile], 'available', $claims, [], $input, $visual, [], ['policy_version' => 'test']);
    }

    private function claim(string $id, string $text, string $role, string $origin, string $eligibility = 'eligible', array $reasons = []): array
    {
        return ['claim_id' => $id, 'claim_revision' => 1, 'text' => $text, 'original_subject' => ['id' => self::SUBJECT, 'type' => 'model'], 'resolved_primary_subject' => ['id' => self::SUBJECT, 'type' => 'model'], 'retrieval_origin' => $origin, 'graph_path' => $origin === 'neighborhood' ? [['source' => 'model:' . self::SUBJECT, 'predicate' => 'uses_movement', 'target' => 'movement:1']] : [], 'eligibility' => $eligibility, 'editorial_role' => $role, 'exclusion_reasons' => $reasons, 'evidence' => ['status' => 'eligible'], 'provenance_references' => ['source_ids' => ['source-1'], 'evidence_ids' => ['evidence-1']]];
    }
}
