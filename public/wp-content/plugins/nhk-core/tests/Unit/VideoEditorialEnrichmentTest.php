<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\{VideoCompletenessPolicy, VideoEditorialEnrichmentService, VideoEditorialQualityPolicy};
use NHK\Core\Domain\Video\{VideoEditorialEnrichmentContext, VideoEditorialQuality};
use PHPUnit\Framework\TestCase;

final class VideoEditorialEnrichmentTest extends TestCase
{
    private const VARIANT = '852da54d-457a-4397-a16d-52d9452ba766';
    private const KNOWLEDGE = '01a072e8-aa9d-71a2-b8bc-7012abdd813f';

    public function test_enrichment_keeps_specimen_source_and_canonical_layers_distinct(): void
    {
        $result = (new VideoEditorialEnrichmentService())->enrich(
            ['title' => 'Số tham chiếu Odo 36/8'],
            new VideoEditorialEnrichmentContext(
                specimenFacts: [['text' => 'mặt số nổi nằm ngang']],
                sourceFacts: [['text' => 'nguồn mô tả bản nhạc Gai-Carillon']],
                canonicalContext: [['text' => 'Variant Odo 36/8', 'entity_id' => self::VARIANT, 'entity_type' => 'variant']],
                relatedKnowledge: [['id' => self::KNOWLEDGE, 'title' => 'Bối cảnh âm thanh']]
            )
        );

        self::assertSame(VideoEditorialQuality::COMPLETE, $result['content_quality']['status']);
        self::assertStringContainsString('chính hiện vật', $result['editorial']['body']);
        self::assertStringContainsString('Trong bối cảnh tri thức NHK', $result['editorial']['body']);
        self::assertSame('SPECIMEN', $result['editorial']['facts'][0]['provenance']);
        self::assertSame('SOURCE_FACT', $result['editorial']['facts'][1]['provenance']);
        self::assertSame('CANONICAL_CONTEXT', $result['editorial']['context'][0]['provenance']);
        self::assertSame(self::KNOWLEDGE, $result['editorial']['related_knowledge'][0]['id']);
    }

    public function test_existing_knowledge_is_reused_and_no_new_knowledge_is_created_for_length(): void
    {
        $result = (new VideoEditorialEnrichmentService())->enrich(
            ['title' => 'Video Odo 36/8'],
            VideoEditorialEnrichmentContext::fromArray([
                'canonical_context' => [['text' => 'Variant Odo 36/8', 'id' => self::VARIANT]],
                'related_knowledge' => [['id' => self::KNOWLEDGE, 'title' => 'Knowledge đã có']],
            ])
        );

        self::assertCount(1, $result['editorial']['related_knowledge']);
        self::assertSame(self::KNOWLEDGE, $result['editorial']['related_knowledge'][0]['id']);
        self::assertArrayNotHasKey('create_knowledge', $result);
        self::assertArrayNotHasKey('knowledge_mutation', $result);
    }

    public function test_content_quality_rejects_trivial_or_cut_off_body(): void
    {
        $quality = (new VideoEditorialQualityPolicy())->evaluate([
            'title' => 'Video Odo 36/8',
            'summary' => 'Video Odo 36/8',
            'body' => 'Video Odo 36/8',
            'why_this_matters' => 'Video Odo 36/8',
        ]);

        self::assertSame(VideoEditorialQuality::NEEDS_REVIEW, $quality->status);
        self::assertContains('EDITORIAL_BODY_TRIVIAL', $quality->blockers);
        self::assertContains('EDITORIAL_SUMMARY_TRIVIAL', $quality->blockers);
    }

    public function test_content_quality_accepts_context_rich_scoped_body(): void
    {
        $context = new VideoEditorialEnrichmentContext(
            specimenFacts: [['text' => 'côn nguyên bản']],
            sourceFacts: [['text' => 'âm thanh được ghi trong nguồn video']],
            canonicalContext: [['text' => 'Variant Odo 36/8', 'id' => self::VARIANT]],
        );
        $editorial = (new VideoEditorialEnrichmentService())->enrich(['title' => 'Số 372'], $context);

        self::assertTrue((new VideoEditorialQualityPolicy())->evaluate($editorial['editorial'], $context)->complete());
    }

    public function test_completeness_recomputes_current_attachment_instead_of_reusing_stale_blocker(): void
    {
        $result = (new VideoCompletenessPolicy())->evaluate([
            'source' => ['identity_valid' => true, 'availability' => 'available', 'embeddable' => true],
            'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
            'editorial' => ['title' => 'Video', 'summary' => 'Tóm tắt', 'body' => 'Nội dung'],
            'category' => ['primary' => ['key' => '01']],
            'semantic_attachments' => [[
                'predicate' => 'about', 'target_uuid' => self::VARIANT,
                'evidence_refs' => [['evidence_id' => self::KNOWLEDGE]],
            ]],
            'completeness' => ['blockers' => ['NO_SEMANTIC_ATTACHMENT']],
            'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'seo' => ['title' => 'Video', 'description' => 'Tóm tắt'],
        ]);

        self::assertTrue($result->publishable);
        self::assertNotContains('NO_SEMANTIC_ATTACHMENT', $result->blockers);
    }

    public function test_canonical_completeness_reconciliation_requires_verified_current_attachment(): void
    {
        $policy = new VideoCompletenessPolicy();
        $package = [
            'source' => ['identity_valid' => true, 'availability' => 'available', 'embeddable' => true],
            'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
            'editorial' => ['title' => 'Video', 'summary' => 'Tóm tắt', 'body' => 'Nội dung'],
            'category' => ['primary' => ['key' => '01']],
            'completeness' => ['blockers' => ['NO_SEMANTIC_ATTACHMENT']],
            'embed_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'seo' => ['title' => 'Video', 'description' => 'Tóm tắt'],
        ];

        $withoutRelation = $policy->evaluateAfterCanonicalReadBack($package, []);
        self::assertContains('NO_SEMANTIC_ATTACHMENT', $withoutRelation->blockers);

        $withRelation = $policy->evaluateAfterCanonicalReadBack($package, [[
            'predicate' => 'about',
            'target_uuid' => self::VARIANT,
            'evidence_refs' => [['evidence_id' => self::KNOWLEDGE]],
        ]]);
        self::assertNotContains('NO_SEMANTIC_ATTACHMENT', $withRelation->blockers);
    }

    public function test_video_completeness_requires_explicit_content_complete_status(): void
    {
        $result = (new VideoCompletenessPolicy())->evaluate([
            'source' => ['identity_valid' => true, 'availability' => 'available', 'embeddable' => true],
            'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
            'editorial' => ['title' => 'Video', 'summary' => 'Tóm tắt', 'body' => 'Nội dung'],
            'content_quality' => ['status' => VideoEditorialQuality::NEEDS_REVIEW],
            'category' => ['primary' => ['key' => '01']],
            'semantic_attachments' => [[
                'predicate' => 'about', 'target_uuid' => self::VARIANT,
                'evidence_refs' => [['evidence_id' => self::KNOWLEDGE]],
            ]],
            'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'seo' => ['title' => 'Video', 'description' => 'Tóm tắt'],
        ]);

        self::assertFalse($result->publishable);
        self::assertContains('CONTENT_NEEDS_REVIEW', $result->blockers);
    }
}
