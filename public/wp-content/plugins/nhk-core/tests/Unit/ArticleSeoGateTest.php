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
}
