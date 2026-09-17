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

    public function test_capture_article_requires_structurally_valid_media_usage_readback_instead_of_legacy_slot_flags(): void
    {
        $research = new ArticleResearchResult(
            ['status' => 'resolved', 'primary' => ['id' => 'classification-1', 'type' => 'classification']],
            ['status' => 'available'], ['classification' => 'NO_OVERLAP'], ['claims' => [], 'sources' => [], 'evidence' => []], [], [],
            ['status' => 'EXISTING', 'category' => ['id' => 4]], [], [], ['slug_intent' => 'clock'], ['status' => 'PASS'], [], [], true,
        );
        $media = [
            'article_media_reconciliation' => 'REQUIRED_BEFORE_PUBLICATION_RESEARCH',
            'media_complete' => true,
            'slots' => [
                'featured_primary' => ['placeholder' => false, 'state' => 'MEDIA_COMPLETE'],
                'inline_primary' => ['placeholder' => false, 'state' => 'MEDIA_COMPLETE'],
            ],
            'canonical_readback' => [],
        ];

        $evidence = (new CaptureArticlePreflightHandoff())->build($research, $media, ['status' => 'SKIPPED'], [
            'post_id' => 573,
            'slug' => 'clock',
            'permalink' => '/clock/',
        ]);

        self::assertFalse($evidence['media_usage_complete']);
        self::assertSame('PENDING', $evidence['requirements']['article_media']['state']);
        self::assertFalse($evidence['research_acceptable']);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $evidence['fresh_preflight_blockers']);
    }

    public function test_capture_article_accepts_only_the_complete_media_usage_packet_shape(): void
    {
        $research = new ArticleResearchResult(
            ['status' => 'resolved', 'primary' => ['id' => 'classification-1', 'type' => 'classification']],
            ['status' => 'available'], ['classification' => 'NO_OVERLAP'], ['claims' => [], 'sources' => [], 'evidence' => []], [], [],
            ['status' => 'EXISTING', 'category' => ['id' => 4]], [], [], ['slug_intent' => 'clock'], ['status' => 'PASS'], [], [], true,
        );
        $media = [
            'article_media_reconciliation' => 'REQUIRED_BEFORE_PUBLICATION_RESEARCH',
            'media_complete' => false,
            'canonical_readback' => [
                'media_usage' => [
                    'state' => 'VERIFIED',
                    'endpoint_type' => 'wp_post',
                    'endpoint_key' => '1:573',
                    'roles' => ['featured_primary', 'inline_primary'],
                    'usage_ids' => ['usage-featured', 'usage-inline'],
                    'source' => 'ARTICLE_MEDIA_RECONCILIATION',
                ],
            ],
        ];

        $evidence = (new CaptureArticlePreflightHandoff())->build($research, $media, ['status' => 'SKIPPED'], [
            'post_id' => 573,
            'slug' => 'clock',
            'permalink' => '/clock/',
        ]);

        self::assertTrue($evidence['media_usage_complete']);
        self::assertTrue($evidence['research_acceptable']);
        self::assertSame('VERIFIED', $evidence['requirements']['article_media']['state']);
    }

    public function test_capture_article_rejects_unregistered_roles_and_extra_usage_ids_in_media_readback(): void
    {
        $research = new ArticleResearchResult(
            ['status' => 'resolved', 'primary' => ['id' => 'classification-1', 'type' => 'classification']],
            ['status' => 'available'], ['classification' => 'NO_OVERLAP'], ['claims' => [], 'sources' => [], 'evidence' => []], [], [],
            ['status' => 'EXISTING', 'category' => ['id' => 4]], [], [], ['slug_intent' => 'clock'], ['status' => 'PASS'], [], [], true,
        );
        $media = [
            'article_media_reconciliation' => 'REQUIRED_BEFORE_PUBLICATION_RESEARCH',
            'canonical_readback' => [
                'media_usage' => [
                    'state' => 'VERIFIED',
                    'endpoint_type' => 'wp_post',
                    'endpoint_key' => '1:573',
                    'roles' => ['featured_primary', 'inline_primary', 'contextual'],
                    'usage_ids' => ['usage-featured', 'usage-inline', 'usage-contextual'],
                    'source' => 'ARTICLE_MEDIA_RECONCILIATION',
                ],
            ],
        ];

        $evidence = (new CaptureArticlePreflightHandoff())->build($research, $media, ['status' => 'SKIPPED'], [
            'post_id' => 573,
            'slug' => 'clock',
            'permalink' => '/clock/',
        ]);

        self::assertFalse($evidence['media_usage_complete']);
        self::assertFalse($evidence['research_acceptable']);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $evidence['fresh_preflight_blockers']);
        self::assertSame('PENDING', $evidence['requirements']['article_media']['state']);
    }

    public function test_capture_article_rejects_duplicate_roles_and_usage_ids_in_media_readback(): void
    {
        $research = new ArticleResearchResult(
            ['status' => 'resolved', 'primary' => ['id' => 'classification-1', 'type' => 'classification']],
            ['status' => 'available'], ['classification' => 'NO_OVERLAP'], ['claims' => [], 'sources' => [], 'evidence' => []], [], [],
            ['status' => 'EXISTING', 'category' => ['id' => 4]], [], [], ['slug_intent' => 'clock'], ['status' => 'PASS'], [], [], true,
        );
        $media = [
            'article_media_reconciliation' => 'REQUIRED_BEFORE_PUBLICATION_RESEARCH',
            'canonical_readback' => [
                'media_usage' => [
                    'state' => 'VERIFIED',
                    'endpoint_type' => 'wp_post',
                    'endpoint_key' => '1:573',
                    'roles' => ['featured_primary', 'inline_primary', 'featured_primary'],
                    'usage_ids' => ['usage-featured', 'usage-inline', 'usage-featured'],
                    'source' => 'ARTICLE_MEDIA_RECONCILIATION',
                ],
            ],
        ];

        $evidence = (new CaptureArticlePreflightHandoff())->build($research, $media, ['status' => 'SKIPPED'], [
            'post_id' => 573,
            'slug' => 'clock',
            'permalink' => '/clock/',
        ]);

        self::assertFalse($evidence['media_usage_complete']);
        self::assertFalse($evidence['research_acceptable']);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $evidence['fresh_preflight_blockers']);
    }

    public function test_handoff_preserves_non_applicable_semantic_requirement_while_article_owners_remain_required(): void
    {
        $research = new ArticleResearchResult(
            ['status' => 'resolved', 'primary' => ['id' => 'classification-1', 'type' => 'classification']],
            ['status' => 'available'], ['classification' => 'NO_OVERLAP'], ['claims' => [], 'sources' => [], 'evidence' => []], [], [],
            ['status' => 'EXISTING', 'category' => ['id' => 4]], [], [], ['slug_intent' => 'clock'], ['status' => 'PASS'], [], [], true,
        );
        $semantic = [
            'status' => 'SKIPPED',
            'requirements' => [
                'semantic_delta' => [
                    'applicability' => 'NOT_APPLICABLE',
                    'policy' => 'VERIFY',
                    'state' => 'SKIPPED',
                    'evidence' => ['intent' => 'IMAGE_ARTICLE', 'status' => 'NONE'],
                ],
            ],
        ];
        $media = [
            'state' => 'MEDIA_COMPLETE',
            'slots' => [
                'featured_primary' => ['placeholder' => false, 'state' => 'MEDIA_COMPLETE'],
                'inline_primary' => ['placeholder' => false, 'state' => 'MEDIA_COMPLETE'],
            ],
        ];

        $evidence = (new CaptureArticlePreflightHandoff())->build($research, $media, $semantic, ['post_id' => 573, 'slug' => 'vedette-37', 'permalink' => '/vedette-37/']);

        self::assertSame('NOT_APPLICABLE', $evidence['requirements']['semantic_delta']['applicability']);
        self::assertSame('REQUIRED', $evidence['requirements']['article_media']['applicability']);
        self::assertSame('REQUIRED', $evidence['requirements']['public_route']['applicability']);
        self::assertSame('NOT_APPLICABLE', $evidence['requirements']['rendered_public']['applicability']);
        self::assertSame('SKIPPED', $evidence['requirements']['rendered_public']['state']);
    }
}
