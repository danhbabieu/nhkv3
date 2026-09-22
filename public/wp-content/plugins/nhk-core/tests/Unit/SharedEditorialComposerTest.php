<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{EditorialContextPack, EditorialPlan, EditorialKnowledgeSelector, ReaderJourneyPlanner, SharedEditorialComposer};
use PHPUnit\Framework\TestCase;

final class SharedEditorialComposerTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';

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

    private function pack(string $profile, array $claims, array $input = [], array $visual = []): EditorialContextPack
    {
        return new EditorialContextPack('available', ['id' => self::SUBJECT, 'type' => 'model'], '3 phiên bản vách máy Odo 36', ['profile' => $profile], 'available', $claims, [], $input, $visual, [], ['policy_version' => 'test']);
    }

    private function claim(string $id, string $text, string $role, string $origin, string $eligibility = 'eligible', array $reasons = []): array
    {
        return ['claim_id' => $id, 'claim_revision' => 1, 'text' => $text, 'original_subject' => ['id' => self::SUBJECT, 'type' => 'model'], 'resolved_primary_subject' => ['id' => self::SUBJECT, 'type' => 'model'], 'retrieval_origin' => $origin, 'graph_path' => $origin === 'neighborhood' ? [['source' => 'model:' . self::SUBJECT, 'predicate' => 'uses_movement', 'target' => 'movement:1']] : [], 'eligibility' => $eligibility, 'editorial_role' => $role, 'exclusion_reasons' => $reasons, 'evidence' => ['status' => 'eligible'], 'provenance_references' => ['source_ids' => ['source-1'], 'evidence_ids' => ['evidence-1']]];
    }
}
