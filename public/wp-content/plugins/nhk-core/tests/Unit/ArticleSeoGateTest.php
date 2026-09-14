<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticleIntentOverlapPlanner;
use NHK\Core\Application\Article\ArticleSeoGate;
use PHPUnit\Framework\TestCase;

final class ArticleSeoGateTest extends TestCase
{
    protected function setUp(): void { self::assertTrue(class_exists(ArticleSeoGate::class)); self::assertTrue(class_exists(ArticleIntentOverlapPlanner::class)); }

    public function test_differentiated_intent_allows_planning_and_duplicate_recommends_existing_projection(): void
    {
        $planner = new ArticleIntentOverlapPlanner();
        self::assertSame('CREATE_DIFFERENTIATED_ARTICLE', $planner->classify(['intent' => 'history'], ['article_intents' => ['technical'], 'entity_covered' => false, 'video_primary' => false]));
        self::assertSame('ENRICH_EXISTING_ARTICLE', $planner->classify(['intent' => 'technical'], ['article_intents' => ['technical'], 'entity_covered' => false, 'video_primary' => false]));
    }

    public function test_gate_requires_canonical_url_and_indexability_without_changing_article_identity(): void
    {
        $result = (new ArticleSeoGate())->evaluate(['intent' => 'history', 'subject' => ['id' => 'a'], 'canonical_url' => '/a/', 'indexable' => true, 'title' => 'A', 'h1' => 'A', 'media_complete' => true, 'compliance' => 'PASS']);
        self::assertTrue($result['ready']);
        self::assertSame('/a/', $result['canonical_url']);
    }

    public function test_text_article_without_optional_visual_support_is_not_system_blocked(): void
    {
        $result = (new ArticleSeoGate())->evaluate([
            'intent' => 'history',
            'subject' => ['id' => 'a'],
            'canonical_url' => '/a/',
            'indexable' => true,
            'title' => 'A',
            'h1' => 'A',
            'media_complete' => false,
            'media_requirement' => 'OPTIONAL_VISUAL_SUPPORT',
            'media_status' => 'missing',
            'media_blueprint_status' => 'valid',
            'media_pipeline_status' => 'not_requested',
            'compliance' => 'PASS',
        ]);

        self::assertTrue($result['ready']);
        self::assertSame(['OPTIONAL_MEDIA_MISSING'], $result['warnings']);
        self::assertNotContains('MEDIA_INCOMPLETE', $result['blockers']);
        self::assertNotContains('SYSTEM_BLOCKED', $result['blockers']);
    }

    public function test_invalid_blueprint_and_pipeline_failure_are_distinct_blockers(): void
    {
        $base = [
            'intent' => 'history', 'subject' => ['id' => 'a'], 'canonical_url' => '/a/',
            'indexable' => true, 'title' => 'A', 'h1' => 'A', 'media_complete' => false,
            'media_requirement' => 'OPTIONAL_VISUAL_SUPPORT', 'compliance' => 'PASS',
        ];

        $invalid = (new ArticleSeoGate())->evaluate($base + ['media_blueprint_status' => 'invalid', 'media_pipeline_status' => 'not_requested']);
        self::assertContains('INVALID_MEDIA_BLUEPRINT', $invalid['blockers']);
        self::assertNotContains('MEDIA_PIPELINE_FAILURE', $invalid['blockers']);

        $failed = (new ArticleSeoGate())->evaluate($base + ['media_blueprint_status' => 'valid', 'media_pipeline_status' => 'failed']);
        self::assertContains('MEDIA_PIPELINE_FAILURE', $failed['blockers']);
        self::assertNotContains('INVALID_MEDIA_BLUEPRINT', $failed['blockers']);
    }
}
