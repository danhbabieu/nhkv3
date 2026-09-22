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

        self::assertSame('READY', $result['quality_report']->readiness);
        self::assertSame('article', $result['draft']->profile);
        self::assertSame('claim-1', $result['draft']->claimTrace[0]['claim_id']);
        self::assertSame('/bai-viet/odo-36/', $result['seo_plan']->canonicalUrl);
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

    private function adapter(array $rows): ArticleEditorialAdapter
    {
        $engine = new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static fn (array $subject, array $neighborhood): array => $rows,
        );

        return ArticleEditorialAdapter::fromEngine($engine);
    }
}
