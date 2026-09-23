<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{EditorialContextPack, EditorialDraft, EditorialPlan, SemanticSeoPlan, SemanticSeoPlanner};
use PHPUnit\Framework\TestCase;

final class SemanticSeoPlannerTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';

    public function test_plan_uses_editorial_truth_for_focus_title_h1_meta_and_traceability(): void
    {
        $plan = $this->planner()->plan($this->pack(), $this->editorialPlan(), $this->draft(), [
            'public_identity' => ['canonical_url' => '/mau/odo36/', 'public_eligible' => true, 'canonical_identity' => true],
            'dictionary_terms' => [['term' => 'vách cam', 'concept_id' => 'concept-cam', 'url' => '/phan-loai/vach-cam/', 'public_eligible' => true]],
            'structured_data' => ['type' => 'Article'],
        ]);

        self::assertInstanceOf(SemanticSeoPlan::class, $plan);
        self::assertSame('READY', $plan->readiness);
        self::assertSame('/mau/odo36/', $plan->canonicalUrl);
        self::assertStringContainsString('vách máy', $plan->title);
        self::assertStringContainsString('vách máy', $plan->h1);
        self::assertLessThanOrEqual(160, mb_strlen($plan->metaDescription));
        self::assertTrue(array_reduce($plan->semanticCluster, static fn (bool $found, string $phrase): bool => $found || str_contains($phrase, 'Odo'), false));
        self::assertNotContains('3', $plan->semanticCluster);
        self::assertNotContains('phiên', $plan->semanticCluster);
        self::assertSame(['core-1', 'explain-1'], array_column($plan->claimTrace, 'claim_id'));
        self::assertSame('Article', $plan->structuredData['type']);
    }

    public function test_missing_or_ambiguous_public_identity_fails_closed_without_fallback_slug(): void
    {
        $missing = $this->planner()->plan($this->pack(), $this->editorialPlan(), $this->draft(), ['public_identity' => ['public_eligible' => true, 'canonical_identity' => true]]);
        $ambiguous = $this->planner()->plan($this->pack(), $this->editorialPlan(), $this->draft(), ['public_identity' => ['canonical_url' => '/mau/odo36/', 'public_eligible' => true, 'canonical_identity' => false]]);

        self::assertSame('INCOMPLETE', $missing->readiness);
        self::assertContains('MISSING_PUBLIC_IDENTITY', $missing->blockers);
        self::assertNull($missing->canonicalUrl);
        self::assertSame('BLOCKED', $ambiguous->readiness);
        self::assertContains('AMBIGUOUS_CANONICAL_SUBJECT', $ambiguous->blockers);
    }

    public function test_internal_links_are_bounded_public_canonical_and_semantically_relevant(): void
    {
        $plan = $this->planner()->plan($this->pack(), $this->editorialPlan(), $this->draft(), [
            'public_identity' => ['canonical_url' => '/mau/odo36/', 'public_eligible' => true, 'canonical_identity' => true],
            'internal_link_candidates' => [
                ['id' => 'model-1', 'label' => 'Máy Odo 36', 'url' => '/bo-may/odo-36/', 'public_eligible' => true, 'semantic_relevance' => 0.9],
                ['id' => 'private', 'label' => 'Private', 'url' => '/private/', 'public_eligible' => false, 'semantic_relevance' => 1.0],
                ['id' => 'uuid', 'label' => 'UUID', 'url' => '/' . self::SUBJECT . '/', 'public_eligible' => true, 'semantic_relevance' => 1.0],
                ['id' => 'unrelated', 'label' => 'Nhạc Westminster', 'url' => '/ban-nhac/westminster/', 'public_eligible' => true, 'semantic_relevance' => 0.0],
            ],
        ]);

        self::assertSame(['model-1'], array_column($plan->internalLinks, 'destination_id'));
        self::assertLessThanOrEqual(5, count($plan->internalLinks));
    }

    public function test_dictionary_owner_route_is_used_and_duplicate_intent_is_diagnostic_not_mutation(): void
    {
        $plan = $this->planner()->plan($this->pack(), $this->editorialPlan(), $this->draft(), [
            'public_identity' => ['canonical_url' => '/mau/odo36/', 'public_eligible' => true, 'canonical_identity' => true],
            'dictionary_terms' => [['term' => 'máy Odo 36', 'concept_id' => 'lex-1', 'url' => '/tu-dien/may-odo-36/', 'canonical_owner' => ['id' => 'movement-1', 'url' => '/bo-may/odo-36/', 'public_eligible' => true]]],
            'competing_pages' => [['id' => 'overview', 'subject_id' => self::SUBJECT, 'intent' => 'overview', 'topic' => 'Odo 36 tổng quan'], ['id' => 'duplicate', 'subject_id' => self::SUBJECT, 'intent' => 'machine-recognition', 'topic' => 'vách máy Odo 36']],
        ]);

        self::assertSame('/bo-may/odo-36/', $plan->dictionaryContext[0]['url']);
        self::assertContains('DUPLICATE_INTENT_REVIEW', $plan->diagnostics['cannibalization']['reasons']);
        self::assertSame('review', $plan->diagnostics['cannibalization']['status']);
    }

    public function test_article_video_and_image_profiles_share_truth_and_use_profile_projection_hints(): void
    {
        foreach (['article' => 'Article', 'video' => 'VideoObject', 'image' => 'ImageObject'] as $profile => $schema) {
            $pack = $this->pack($profile);
            $plan = $this->planner()->plan($pack, $this->editorialPlan($profile), $this->draft($profile), ['public_identity' => ['canonical_url' => '/mau/odo36/', 'public_eligible' => true, 'canonical_identity' => true], 'structured_data' => ['type' => $schema]]);
            self::assertSame($profile, $plan->profile);
            self::assertSame($schema, $plan->structuredData['type']);
            self::assertSame(['core-1', 'explain-1'], array_column($plan->claimTrace, 'claim_id'));
        }
    }

    public function test_generated_seo_copy_has_no_internal_jargon_and_is_not_evidence(): void
    {
        $plan = $this->planner()->plan($this->pack(), $this->editorialPlan(), $this->draft(), ['public_identity' => ['canonical_url' => '/mau/odo36/', 'public_eligible' => true, 'canonical_identity' => true]]);

        foreach ([$plan->title, $plan->h1, $plan->metaDescription, $plan->openGraph['description']] as $copy) {
            foreach (['canonical UUID', 'stable key', 'Graph', 'Governance', 'MCP', 'Evidence', 'Claim revision'] as $forbidden) self::assertStringNotContainsStringIgnoringCase($forbidden, $copy);
        }
        self::assertArrayNotHasKey('evidence', $plan->toArray());
    }

    public function test_provenance_only_claim_is_not_used_as_reader_facing_meta_support(): void
    {
        $provenance = ['claim_id' => 'provenance', 'claim_revision' => 1, 'text' => 'Nguồn xác nhận phạm vi của đối tượng.', 'eligibility' => 'eligible', 'semantic_role' => 'PROVENANCE_ONLY', 'publicly_composable' => false, 'editorial_role' => 'CORE'];
        $fact = ['claim_id' => 'fact', 'claim_revision' => 1, 'text' => 'Odo 36/8 dùng máy ba vách.', 'eligibility' => 'eligible', 'semantic_role' => 'READER_FACT', 'publicly_composable' => true, 'editorial_role' => 'CORE'];
        $pack = new EditorialContextPack('available', ['id' => self::SUBJECT, 'type' => 'variant'], 'Odo 36/8', ['profile' => 'video'], 'available', [$provenance, $fact], [], ['raw_input' => 'Video Odo 36/8']);
        $plan = $this->planner()->plan($pack, new EditorialPlan('available', 'video', ['id' => self::SUBJECT, 'type' => 'variant'], 'Odo 36/8', []), new EditorialDraft('available', 'video', 'Odo 36/8', 'Odo 36/8 dùng máy ba vách.', 'Odo 36/8 dùng máy ba vách.', [['claim_id' => 'fact']], []), ['public_identity' => ['canonical_url' => '/video/odo-36-8/', 'public_eligible' => true, 'canonical_identity' => true], 'structured_data' => ['type' => 'VideoObject']]);

        self::assertStringNotContainsString('Nguồn xác nhận', $plan->metaDescription);
        self::assertStringContainsString('máy ba vách', $plan->metaDescription);
        self::assertNotContains('provenance', array_column($plan->claimTrace, 'claim_id'));
    }

    public function test_h2_seo_copy_is_differentiated_and_cluster_uses_meaningful_phrases(): void
    {
        $plan = $this->planner()->plan($this->pack(), $this->editorialPlan(), $this->draft(), [
            'public_identity' => ['canonical_url' => '/mau/odo36/', 'public_eligible' => true, 'canonical_identity' => true],
        ]);

        self::assertStringNotContainsString('Odo 36 có ba phiên bản vách máy — Odo 36 có ba phiên bản vách máy', $plan->metaDescription);
        self::assertNotSame($plan->title . ' — ' . $plan->metaDescription, $plan->openGraph['description']);
        foreach ($plan->semanticCluster as $phrase) self::assertGreaterThanOrEqual(2, count(preg_split('/\s+/u', $phrase) ?: []), $phrase);
        self::assertNotContains('Odo phiên', $plan->semanticCluster);
        self::assertNotContains('bản vách máy', $plan->semanticCluster);
        self::assertCount(count($plan->semanticCluster), $plan->diagnostics['semantic_cluster_sources']);
    }

    public function test_unfulfilled_enumeration_is_narrowed_without_fabricating_members(): void
    {
        $pack = $this->pack();
        $plan = $this->planner()->plan($pack, $this->editorialPlan(), $this->draft(), [
            'public_identity' => ['canonical_url' => '/mau/odo36/', 'public_eligible' => true, 'canonical_identity' => true],
        ]);

        self::assertStringNotContainsString('3 phiên bản', $plan->title);
        self::assertTrue($plan->diagnostics['title_narrowed']);
        self::assertSame('ENUMERATION_PROMISE_UNFULFILLED', $plan->diagnostics['topic_fulfillment']['diagnostic']);
        self::assertStringNotContainsString('vách hở', $plan->metaDescription);
    }

    private function planner(): SemanticSeoPlanner { return new SemanticSeoPlanner(); }

    private function pack(string $profile = 'article'): EditorialContextPack
    {
        $claims = [
            ['claim_id' => 'core-1', 'claim_revision' => 2, 'text' => 'Odo 36 có ba phiên bản vách máy.', 'eligibility' => 'eligible', 'editorial_role' => 'CORE', 'original_subject' => ['id' => self::SUBJECT, 'type' => 'model'], 'retrieval_origin' => 'direct', 'graph_path' => [], 'selection_reason' => 'core topic'],
            ['claim_id' => 'explain-1', 'claim_revision' => 1, 'text' => 'Vách xoáy giúp nhận biết cấu hình máy Odo 36.', 'eligibility' => 'eligible', 'editorial_role' => 'EXPLANATION', 'original_subject' => ['id' => 'movement-1', 'type' => 'movement'], 'retrieval_origin' => 'neighborhood', 'graph_path' => [['source' => 'model:' . self::SUBJECT, 'predicate' => 'uses_movement', 'target' => 'movement:movement-1']], 'selection_reason' => 'explanation'],
        ];
        return new EditorialContextPack('available', ['id' => self::SUBJECT, 'type' => 'model'], '3 phiên bản vách máy của đồng hồ Odo 36', ['profile' => $profile], 'available', $claims, [], ['raw_input' => 'Đây là 3 phiên bản máy của dòng đồng hồ Odo 36'], [], [], []);
    }

    private function editorialPlan(string $profile = 'article'): EditorialPlan
    {
        return new EditorialPlan('available', $profile, ['id' => self::SUBJECT, 'type' => 'model'], '3 phiên bản vách máy của đồng hồ Odo 36', [['id' => 'opening', 'claim_refs' => []], ['id' => 'core', 'claim_refs' => [['claim_id' => 'core-1']]], ['id' => 'explain', 'claim_refs' => [['claim_id' => 'explain-1']]]]);
    }

    private function draft(string $profile = 'article'): EditorialDraft
    {
        return new EditorialDraft('available', $profile, '3 phiên bản vách máy của đồng hồ Odo 36', 'Odo 36 có ba phiên bản vách máy.', 'Odo 36 có ba phiên bản vách máy. Vách xoáy giúp nhận biết cấu hình máy Odo 36.', [['claim_id' => 'core-1', 'claim_revision' => 2], ['claim_id' => 'explain-1', 'claim_revision' => 1]], ['mode' => 'selected_knowledge']);
    }
}
