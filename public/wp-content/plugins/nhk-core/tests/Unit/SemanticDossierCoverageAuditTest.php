<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\SemanticDossierCoverageAudit;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class SemanticDossierCoverageAuditTest extends TestCase
{
    public function test_audit_reports_coverage_absence_without_proposing_semantic_repairs(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($repo = new InMemoryAuthorityRepository(), $types);
        $movement = $authority->create('movement', 'movement-a', 'Machine A');
        $model = $authority->create('model', 'model-a', 'Model A');

        $reader = static function($entity) use ($movement, $model): array {
            if ($entity->canonicalId === $movement->canonicalId) {
                return [
                    'status' => 'AVAILABLE',
                    'identity' => ['type' => 'movement', 'name' => 'Machine A', 'url' => '/bo-may/machine-a/'],
                    'primary_media' => null,
                    'media_gallery' => [],
                    'relation_sections' => ['models' => [['title' => 'Model A']], 'videos' => []],
                    'knowledge' => [
                        'status' => 'AVAILABLE',
                        'claim_count' => 2,
                        'evidence_count' => 1,
                        'coverage' => ['sourced_claim_count' => 1, 'unsourced_claim_count' => 1],
                    ],
                    'coverage' => ['relation_count' => 1, 'media_count' => 0, 'video_count' => 0, 'article_count' => 0],
                    'warnings' => ['PUBLIC_CLAIMS_WITHOUT_EVIDENCE'],
                    'availability' => ['graph' => 'AVAILABLE', 'knowledge' => 'AVAILABLE'],
                ];
            }
            if ($entity->canonicalId === $model->canonicalId) {
                return [
                    'status' => 'UNAVAILABLE',
                    'identity' => null,
                    'coverage' => [],
                    'knowledge' => ['status' => 'UNAVAILABLE'],
                    'warnings' => ['PUBLIC_ROUTE_UNAVAILABLE'],
                    'availability' => ['graph' => 'UNAVAILABLE', 'knowledge' => 'UNAVAILABLE'],
                ];
            }
            return ['status' => 'UNAVAILABLE', 'warnings' => []];
        };

        $report = (new SemanticDossierCoverageAudit($types, $repo, $reader))->run();
        $movementRow = $this->find($report['items'], 'movement', 'movement-a');
        $modelRow = $this->find($report['items'], 'model', 'model-a');

        self::assertSame('COVERAGE_GAPS', $movementRow['status']);
        self::assertContains('MEDIA_REPRESENTATIVE_ABSENT', $movementRow['gaps']);
        self::assertContains('PUBLIC_EVIDENCE_PARTIAL', $movementRow['gaps']);
        self::assertContains('VIDEO_COVERAGE_EMPTY', $movementRow['gaps']);
        self::assertContains('ARTICLE_COVERAGE_EMPTY', $movementRow['gaps']);
        self::assertNotContains('CREATE_RELATION', $movementRow['gaps']);
        self::assertSame('NOT_PUBLIC_READY', $modelRow['status']);
        self::assertContains('PUBLIC_ROUTE_UNAVAILABLE', $modelRow['gaps']);
        self::assertSame(2, $report['summary']['entity_count']);
        self::assertSame(1, $report['summary']['public_ready_count']);
        self::assertSame(1, $report['summary']['not_public_ready_count']);
    }

    public function test_audit_marks_complete_coverage_without_requiring_video_or_article_as_semantic_truth(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($repo = new InMemoryAuthorityRepository(), $types);
        $entity = $authority->create('component', 'component-a', 'Component A');
        $reader = static fn($candidate): array => $candidate->canonicalId === $entity->canonicalId ? [
            'status' => 'AVAILABLE',
            'identity' => ['type' => 'component', 'name' => 'Component A', 'url' => '/linh-kien/component-a/'],
            'primary_media' => ['url' => '/anh/a.webp'],
            'media_gallery' => [['url' => '/anh/a.webp']],
            'relation_sections' => ['movements' => [['title' => 'Machine A']]],
            'knowledge' => ['status' => 'AVAILABLE', 'claim_count' => 1, 'evidence_count' => 1, 'coverage' => ['sourced_claim_count' => 1, 'unsourced_claim_count' => 0]],
            'coverage' => ['relation_count' => 1, 'media_count' => 1, 'video_count' => 0, 'article_count' => 0],
            'warnings' => [],
            'availability' => ['graph' => 'AVAILABLE', 'knowledge' => 'AVAILABLE'],
        ] : ['status' => 'UNAVAILABLE'];

        $report = (new SemanticDossierCoverageAudit($types, $repo, $reader, false))->run();
        $row = $this->find($report['items'], 'component', 'component-a');

        self::assertSame('COMPLETE_CORE', $row['status']);
        self::assertNotContains('VIDEO_COVERAGE_EMPTY', $row['gaps']);
        self::assertNotContains('ARTICLE_COVERAGE_EMPTY', $row['gaps']);
    }

    private function find(array $items, string $type, string $stableKey): array
    {
        foreach ($items as $item) if (($item['type'] ?? '') === $type && ($item['stable_key'] ?? '') === $stableKey) return $item;
        self::fail("Missing audit row {$type}:{$stableKey}");
    }
}
