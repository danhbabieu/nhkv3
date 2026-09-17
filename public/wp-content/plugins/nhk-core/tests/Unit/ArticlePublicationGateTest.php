<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticlePublicationGate;
use NHK\Core\Domain\Article\EditorialPostState;
use PHPUnit\Framework\TestCase;

final class ArticlePublicationGateTest extends TestCase
{
    public function test_gate_requires_all_verified_boundaries_and_matching_draft_token(): void
    {
        $draft = $this->draft();
        $evidence = $this->evidence();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);
        self::assertTrue($result->eligible);
        self::assertSame([], $result->blockers);
    }

    public function test_gate_reports_explicit_blockers_and_never_returns_generic_failure(): void
    {
        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, ['claim_compliance_acceptable' => false], str_repeat('0', 64));
        self::assertFalse($result->eligible);
        self::assertContains('EDITORIAL_CAS_REQUIRED', $result->blockers);
        self::assertContains('RESEARCH_PREFLIGHT_BLOCKED', $result->blockers);
        self::assertContains('PUBLIC_CLAIM_COMPLIANCE_BLOCKED', $result->blockers);
        self::assertArrayHasKey('blockers', $result->toArray());
        self::assertArrayNotHasKey('ok', $result->toArray());
    }

    public function test_gate_rejects_published_or_identity_incomplete_state(): void
    {
        $draft = new EditorialPostState(1, '1:1', 'post', 'publish', 'Title', 'Body', '', '', '', 1, 1);
        $result = (new ArticlePublicationGate())->check($draft, $this->evidence(), $draft->token);
        self::assertFalse($result->eligible);
        self::assertContains('EDITORIAL_POST_NOT_DRAFT', $result->blockers);
        self::assertContains('CANONICAL_PUBLIC_IDENTITY_INVALID', $result->blockers);
    }

    public function test_soft_incomplete_media_links_optional_data_and_rendered_unavailability_do_not_block(): void
    {
        $evidence = $this->evidence();
        $evidence['real_image_requirements_met'] = false;
        $evidence['internal_links_valid'] = false;
        $evidence['structured_data_valid'] = false;
        $evidence['structured_data_status'] = 'incomplete';
        $evidence['rendered_public_verification'] = false;
        $evidence['rendered_public_verification_status'] = 'unavailable';

        $result = (new ArticlePublicationGate())->check($this->draft(), $evidence, $this->draft()->token);

        self::assertTrue($result->eligible);
        self::assertSame([], $result->blockers);
        self::assertContains('REAL_IMAGE_INCOMPLETE', $result->warnings);
        self::assertContains('RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE', $result->warnings);
    }

    public function test_gate_does_not_treat_planning_subject_or_media_candidates_as_persisted_state(): void
    {
        $evidence = $this->evidence();
        $evidence['subject_resolved'] = true;
        $evidence['subject_persistence_status'] = 'unattached_planning_candidate';
        $evidence['media_usage_complete'] = true;
        $evidence['media_snapshot'] = [
            'featured_primary' => ['placeholder' => false],
            'inline_primary' => ['placeholder' => true],
        ];

        $result = (new ArticlePublicationGate())->check($this->draft(), $evidence, $this->draft()->token);

        self::assertContains('SUBJECT_NOT_PERSISTED', $result->blockers);
        self::assertNotContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertNotContains('ARTICLE_MEDIA_INLINE_MISSING', $result->blockers);
        self::assertContains('ARTICLE_MEDIA_INLINE_MISSING', $result->warnings);
    }

    public function test_missing_optional_inline_media_is_a_warning_when_featured_media_is_verified(): void
    {
        $evidence = $this->evidence();
        $evidence['media_usage_complete'] = false;
        $evidence['media_snapshot'] = [
            'featured_primary' => ['placeholder' => false],
            'inline_primary' => ['placeholder' => true],
        ];

        $result = (new ArticlePublicationGate())->check($this->draft(), $evidence, $this->draft()->token);

        self::assertTrue($result->eligible);
        self::assertNotContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertNotContains('ARTICLE_MEDIA_INLINE_MISSING', $result->blockers);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $result->warnings);
        self::assertContains('ARTICLE_MEDIA_INLINE_MISSING', $result->warnings);
    }

    public function test_missing_featured_media_remains_a_publication_blocker(): void
    {
        $evidence = $this->evidence();
        $evidence['media_usage_complete'] = false;
        $evidence['media_snapshot'] = [
            'featured_primary' => ['placeholder' => true],
            'inline_primary' => ['placeholder' => true],
        ];

        $result = (new ArticlePublicationGate())->check($this->draft(), $evidence, $this->draft()->token);

        self::assertFalse($result->eligible);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertContains('ARTICLE_MEDIA_FEATURED_MISSING', $result->blockers);
        self::assertContains('ARTICLE_MEDIA_INLINE_MISSING', $result->warnings);
    }

    public function test_gate_skips_non_applicable_semantic_owner_but_evaluates_required_article_owners(): void
    {
        $evidence = $this->evidence();
        $evidence['semantic_readback_verified'] = false;
        $evidence['requirements'] = $this->requirements();

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertTrue($result->eligible);
        self::assertNotContains('SEMANTIC_READBACK_UNVERIFIED', $result->blockers);
        self::assertNotContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertNotContains('PUBLIC_ROUTE_NOT_READY', $result->blockers);
    }

    public function test_gate_blocks_unverified_required_article_media_even_when_semantic_is_not_applicable(): void
    {
        $evidence = $this->evidence();
        $evidence['semantic_readback_verified'] = false;
        $evidence['requirements'] = $this->requirements('article_media');

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertFalse($result->eligible);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertNotContains('SEMANTIC_READBACK_UNVERIFIED', $result->blockers);
    }

    public function test_gate_blocks_unverified_required_public_route_even_when_semantic_is_not_applicable(): void
    {
        $evidence = $this->evidence();
        $evidence['semantic_readback_verified'] = false;
        $evidence['requirements'] = $this->requirements('public_route');

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertFalse($result->eligible);
        self::assertContains('PUBLIC_ROUTE_NOT_READY', $result->blockers);
        self::assertNotContains('SEMANTIC_READBACK_UNVERIFIED', $result->blockers);
    }

    public function test_gate_blocks_unverified_required_rendered_public_readback_even_when_semantic_is_not_applicable(): void
    {
        $evidence = $this->evidence();
        $evidence['semantic_readback_verified'] = false;
        $evidence['requirements'] = $this->requirements('rendered_public');

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertFalse($result->eligible);
        self::assertContains('RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE', $result->blockers);
        self::assertNotContains('SEMANTIC_READBACK_UNVERIFIED', $result->blockers);
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        return array_fill_keys([
            'research_acceptable', 'subject_resolved', 'duplicate_intent_handled',
            'category_resolved', 'semantic_plan_complete', 'semantic_readback_verified',
            'media_usage_complete', 'real_image_requirements_met', 'claim_compliance_acceptable',
            'seo_projection_valid', 'internal_links_valid', 'structured_data_valid', 'public_route_ready', 'rendered_public_verification',
        ], true);
    }

    /** @return array<string,array<string,string>> */
    private function requirements(string $unverified = ''): array
    {
        $requirements = [
            'semantic_delta' => ['applicability' => 'NOT_APPLICABLE', 'policy' => 'VERIFY', 'state' => 'SKIPPED'],
            'article_media' => ['applicability' => 'REQUIRED', 'policy' => 'VERIFY', 'state' => 'VERIFIED'],
            'public_route' => ['applicability' => 'REQUIRED', 'policy' => 'VERIFY', 'state' => 'VERIFIED'],
            'rendered_public' => ['applicability' => 'REQUIRED', 'policy' => 'VERIFY', 'state' => 'VERIFIED'],
        ];
        if ($unverified !== '') $requirements[$unverified]['state'] = 'PENDING';
        return $requirements;
    }

    private function draft(): EditorialPostState
    {
        return new EditorialPostState(1, '1:1', 'post', 'draft', 'Title', 'Body', '', 'title', '/title/', 1, 1);
    }
}
