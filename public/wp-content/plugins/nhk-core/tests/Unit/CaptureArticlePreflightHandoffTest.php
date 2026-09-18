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

    public function test_article_media_result_shape_is_translated_to_publication_media_evidence(): void
    {
        $research = new ArticleResearchResult(
            ['status' => 'resolved', 'primary' => ['id' => 'classification-1', 'type' => 'classification']],
            ['status' => 'available'], ['classification' => 'NO_OVERLAP'], ['claims' => [], 'sources' => [], 'evidence' => []], [], [],
            ['status' => 'EXISTING', 'category' => ['id' => 4]], [], [], ['slug_intent' => 'clock'], ['status' => 'PASS'], [], [], true,
        );
        $media = [
            'state' => 'MEDIA_COMPLETE',
            'slot_media' => ['featured_primary' => 'media-1', 'inline_primary' => 'media-1'],
            'slots' => [
                'featured_primary' => ['placeholder' => false, 'state' => 'MEDIA_COMPLETE'],
                'inline_primary' => ['placeholder' => false, 'state' => 'MEDIA_COMPLETE'],
            ],
        ];

        $evidence = (new CaptureArticlePreflightHandoff())->build($research, $media, ['status' => 'APPLIED'], ['slug' => 'clock', 'permalink' => '/clock/']);

        self::assertTrue($evidence['media_usage_complete']);
        self::assertTrue($evidence['real_image_requirements_met']);
    }

    public function test_current_target_scoped_article_media_readback_beats_stale_capture_media_plan(): void
    {
        $research = new ArticleResearchResult(
            ['status' => 'resolved', 'primary' => ['id' => 'classification-1', 'type' => 'classification']],
            [
                'status' => 'available',
                'article_media' => [
                    'media_complete' => true,
                    'featured_primary' => ['media_id' => 'media-current', 'placeholder' => false],
                    'inline_primary' => ['media_id' => 'media-current', 'placeholder' => false],
                    'slots' => [
                        'featured_primary' => ['media_id' => 'media-current', 'placeholder' => false],
                        'inline_primary' => ['media_id' => 'media-current', 'placeholder' => false],
                    ],
                ],
            ],
            ['classification' => 'NO_OVERLAP'],
            ['claims' => [], 'sources' => [], 'evidence' => []], [], [],
            ['status' => 'EXISTING', 'category' => ['id' => 4]], [], [], ['slug_intent' => 'clock'], ['status' => 'PASS'], [], [], true,
        );

        $evidence = (new CaptureArticlePreflightHandoff())->build($research, ['media_complete' => false, 'slots' => [
            'featured_primary' => ['placeholder' => true],
            'inline_primary' => ['placeholder' => true],
        ]], ['status' => 'APPLIED'], ['slug' => 'clock', 'permalink' => '/clock/']);

        self::assertTrue($evidence['media_usage_complete']);
        self::assertFalse($evidence['media_snapshot']['featured_primary']['placeholder']);
        self::assertFalse($evidence['media_snapshot']['inline_primary']['placeholder']);
    }

    public function test_semantic_delta_not_required_is_verified_from_current_subject_readback(): void
    {
        $research = new ArticleResearchResult(
            [
                'status' => 'resolved',
                'primary' => ['id' => 'subject-1', 'type' => 'model'],
                'persistence' => ['status' => 'attached', 'subject_id' => 'subject-1', 'post_id' => 123],
            ],
            ['status' => 'available', 'posts' => [['id' => '123', 'subject_ids' => ['subject-1']]], 'article_media' => [
                'media_complete' => true,
                'featured_primary' => ['placeholder' => false],
                'inline_primary' => ['placeholder' => false],
            ]],
            ['classification' => 'NO_OVERLAP'],
            ['claims' => [], 'sources' => [], 'evidence' => []], [], [],
            ['status' => 'EXISTING', 'category' => ['id' => 4]], [], [], ['slug_intent' => 'clock'], ['status' => 'PASS'], [], [], true,
        );

        $evidence = (new CaptureArticlePreflightHandoff())->build($research, ['media_complete' => false], ['status' => 'SKIPPED', 'blockers' => []], ['post_id' => 123, 'slug' => 'clock', 'permalink' => '/clock/']);

        self::assertTrue($evidence['semantic_readback_verified']);
    }
}
