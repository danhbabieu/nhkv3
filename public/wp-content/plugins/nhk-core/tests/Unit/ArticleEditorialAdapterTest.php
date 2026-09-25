<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticleEditorialAdapter;
use NHK\Core\Application\Semantic\ClaimRetrievalEngine;
use PHPUnit\Framework\TestCase;

final class ArticleEditorialAdapterTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';

    public function test_article_adapter_reuses_shared_pipeline_and_returns_transient_article_projection(): void
    {
        $adapter = $this->adapter([
            ['id' => 'claim-1', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Odo 36 có ba phiên bản vách máy.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE'],
        ]);

        $result = $adapter->prepare([
            'raw_input' => 'Tìm hiểu 3 phiên bản vách máy của đồng hồ Odo 36',
            'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model']],
            'public_identity' => ['canonical_url' => '/bai-viet/odo-36/', 'canonical_identity' => true, 'public_eligible' => true],
        ]);

        self::assertSame('INCOMPLETE', $result['quality_report']->readiness);
        self::assertContains('ENUMERATION_PROMISE_UNFULFILLED', $result['quality_report']->warnings);
        self::assertSame('article', $result['draft']->profile);
        self::assertSame('claim-1', $result['draft']->claimTrace[0]['claim_id']);
        self::assertSame('/bai-viet/odo-36/', $result['seo_plan']->canonicalUrl);
        self::assertArrayHasKey('pack', $result);
        self::assertArrayHasKey('plan', $result);
    }

    public function test_article_adapter_can_lock_semantic_pack_without_composing_before_media_readback(): void
    {
        $adapter = $this->adapter([
            ['id' => 'claim-1', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Odo 36 có ba phiên bản vách máy.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE'],
        ]);

        $result = $adapter->prepare([
            'raw_input' => 'Bài viết về Odo 36',
            'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model']],
            'defer_composition' => true,
        ]);

        self::assertSame('DEFERRED', $result['status']);
        self::assertNull($result['draft']);
        self::assertArrayHasKey('pack', $result);
        self::assertArrayHasKey('plan', $result);
    }

    public function test_article_adapter_fail_closed_when_shared_quality_is_blocked(): void
    {
        $adapter = $this->adapter([
            ['id' => 'claim-1', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Odo 36 có ba phiên bản vách máy.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'MISSING'],
        ]);

        $result = $adapter->prepare([
            'raw_input' => 'Tìm hiểu 3 phiên bản vách máy của đồng hồ Odo 36',
            'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model']],
            'public_identity' => ['canonical_url' => '', 'canonical_identity' => false, 'public_eligible' => false],
        ]);

        self::assertSame('BLOCKED', $result['quality_report']->readiness);
        self::assertContains('SEO_NOT_READY', $result['quality_report']->blockers);
        self::assertSame('article', $result['profile']);
    }

    public function test_prepared_context_blocks_unselected_video_knowledge_from_article_composer(): void
    {
        $adapter = $this->adapter([
            ['id' => 'model-claim', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Odo 36 là một mẫu đồng hồ cơ.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE'],
            ['id' => 'video-claim', 'subject_id' => 'video-c', 'subject_type' => 'video', 'text' => 'Video này xác nhận Variant C có mặt số đặc biệt.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE', 'relation_path' => [['source' => 'model:' . self::SUBJECT, 'predicate' => 'has_video', 'target' => 'video:video-c']]],
        ]);

        $result = $adapter->prepare([
            'raw_input' => 'Bài viết về Odo 36 có nhắc Music B',
            'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model']],
            'prepared_context' => [
                'subject_resolution_packet' => ['canonical_subject_id' => self::SUBJECT, 'entity_type' => 'model'],
                'selected_related_entities' => [['id' => 'music-b', 'type' => 'music', 'name' => 'Music B']],
            ],
        ]);

        self::assertNotContains('video-claim', array_column($result['draft']->claimTrace, 'claim_id'));
        self::assertStringNotContainsString('Variant C', $result['draft']->body);
    }

    private function adapter(array $rows): ArticleEditorialAdapter
    {
        $engine = new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static fn (array $subject, array $neighborhood): array => $rows,
        );

        return ArticleEditorialAdapter::fromEngine($engine);
    }
}
