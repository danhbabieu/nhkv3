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

    /** @return array<string,bool> */
    private function evidence(): array
    {
        return array_fill_keys([
            'research_acceptable', 'subject_resolved', 'duplicate_intent_handled',
            'category_resolved', 'semantic_plan_complete', 'semantic_readback_verified',
            'media_usage_complete', 'real_image_requirements_met', 'claim_compliance_acceptable',
            'seo_projection_valid', 'internal_links_valid', 'structured_data_valid', 'public_route_ready', 'rendered_public_verification',
        ], true);
    }

    private function draft(): EditorialPostState
    {
        return new EditorialPostState(1, '1:1', 'post', 'draft', 'Title', 'Body', '', 'title', '/title/', 1, 1);
    }
}
