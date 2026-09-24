<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticleEditorialAdapter;
use NHK\Core\Application\Semantic\ClaimRetrievalEngine;
use NHK\Core\Application\Video\VideoEditorialAdapter;
use PHPUnit\Framework\TestCase;

final class SemanticProductionWiringTest extends TestCase
{
    public function test_article_adapter_composes_shared_decomposition_and_need_aware_retrieval(): void
    {
        $seen = [];
        $adapter = ArticleEditorialAdapter::fromEngine($this->engine($seen));
        $result = $adapter->prepare([
            'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'variant']],
            'raw_input' => 'Subject configuration recognition',
            'components' => [
                ['facet_key' => 'configuration', 'concept_key' => 'layout', 'origin' => 'USER_EXPLICIT'],
                ['facet_key' => 'recognition', 'concept_key' => 'markers', 'origin' => 'MACHINE_DERIVED'],
            ],
        ]);

        self::assertSame(['configuration', 'recognition'], array_column($result['shared_enrichment']['semantic_needs'], 'facet_key'));
        self::assertSame(['subject-1', 'subject-1'], array_column(array_column($result['shared_enrichment']['semantic_needs'], 'canonical_subject'), 'id'));
        self::assertSame(['configuration', 'recognition'], array_values(array_unique(array_column($seen, 'facet_key'))));
    }

    public function test_video_adapter_uses_the_same_shared_decomposition_and_need_aware_retrieval(): void
    {
        $seen = [];
        $adapter = VideoEditorialAdapter::fromEngine($this->engine($seen));
        $result = $adapter->prepare([
            'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'variant']],
            'source' => ['source_title' => 'Subject'],
            'user_hint' => 'Subject configuration recognition',
            'components' => [
                ['facet_key' => 'configuration', 'concept_key' => 'layout', 'origin' => 'USER_EXPLICIT'],
                ['facet_key' => 'recognition', 'concept_key' => 'markers', 'origin' => 'MACHINE_DERIVED'],
            ],
        ]);

        self::assertSame(['configuration', 'recognition'], array_column($result['shared_enrichment']['semantic_needs'], 'facet_key'));
        self::assertSame(['configuration', 'recognition'], array_values(array_unique(array_column($seen, 'facet_key'))));
    }

    private function engine(array &$seen): ClaimRetrievalEngine
    {
        return new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static function (array $subject, array $neighborhood, array $need = []) use (&$seen): array {
                $seen[] = ['facet_key' => (string) ($need['facet_key'] ?? '')];
                return [[
                    'id' => 'claim-' . (string) ($need['facet_key'] ?? 'unknown'),
                    'claim_id' => 'claim-' . (string) ($need['facet_key'] ?? 'unknown'),
                    'subject_id' => 'subject-1', 'subject_type' => 'variant',
                    'facet' => (string) ($need['facet_key'] ?? ''), 'concept' => (string) ($need['concept_key'] ?? ''),
                    'text' => 'Subject ' . (string) ($need['facet_key'] ?? '') . ' ' . (string) ($need['concept_key'] ?? ''),
                    'scope' => 'variant', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
                ]];
            },
        );
    }
}
