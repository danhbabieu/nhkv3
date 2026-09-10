<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CaptureArticlePreflightHandoff;
use NHK\Core\Domain\Article\ArticleResearchResult;
use PHPUnit\Framework\TestCase;

final class CaptureArticlePreflightHandoffTest extends TestCase
{
    public function test_fresh_owner_read_replaces_stale_research_and_duplicate_diagnostics(): void
    {
        $research = new ArticleResearchResult(
            ['status' => 'resolved', 'primary' => ['id' => 'variant-1', 'type' => 'variant']],
            ['status' => 'available'],
            ['classification' => 'NO_OVERLAP'],
            ['claims' => [], 'sources' => [], 'evidence' => []],
            [], [],
            ['status' => 'EXISTING', 'category' => ['id' => 4, 'name' => 'Tri thức đồng hồ']],
            ['media_complete' => false],
            ['candidates' => []],
            ['slug_intent' => 'variant-a'],
            ['status' => 'HUMAN_REVIEW_REQUIRED'],
            [], [], true,
        );
        $handoff = new CaptureArticlePreflightHandoff();

        $evidence = $handoff->build($research, ['media_complete' => false], ['status' => 'APPLIED'], ['post_id' => 123, 'slug' => 'variant-a', 'permalink' => '/?p=123']);

        self::assertTrue($evidence['fresh_preflight']);
        self::assertTrue($evidence['research_acceptable']);
        self::assertTrue($evidence['duplicate_intent_handled']);
        self::assertTrue($evidence['category_resolved']);
        self::assertTrue($evidence['semantic_readback_verified']);
        self::assertFalse($evidence['media_usage_complete']);
        self::assertSame([], $evidence['fresh_preflight_blockers']);
        self::assertSame('NO_OVERLAP', $evidence['fresh_overlap']['classification']);
        self::assertFalse($evidence['claim_compliance_acceptable']);
    }
}
