<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\KnowledgeRepairPreviewService;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim};
use PHPUnit\Framework\TestCase;

final class KnowledgeRepairPreviewServiceTest extends TestCase
{
    private const ID = '01a09786-dd67-70e7-9d30-9b8d3931766d';

    public function testUpdatePreviewIsReadOnlyAndReportsPublicImpact(): void
    {
        $claim = new KnowledgeClaim(self::ID, 'nhk:test:repair', 'Internal workflow text', 'fact', ['origin' => 'TEST'], true, 4);
        $claims = $this->createMock(KnowledgeRepository::class);
        $claims->expects(self::once())->method('findByCanonicalId')->with(self::ID)->willReturn($claim);
        $evidence = $this->createMock(EvidenceRepository::class);
        $evidence->expects(self::once())->method('listByClaim')->with(self::ID, true)->willReturn([]);
        $preview = (new KnowledgeRepairPreviewService($claims, $evidence))->preview(['canonical_knowledge_uuid' => self::ID, 'expected_revision' => 4, 'operation' => 'update', 'delta' => ['text' => 'Clean text'], 'reason' => 'cleanup', 'provenance' => ['origin' => 'TEST'], 'cleanup_class' => 'PROCESS_CONTAMINATION']);
        self::assertSame('SAFE_TO_UPDATE', $preview['status']);
        self::assertFalse($preview['projection_public_impact']['stable_key_changes']);
        self::assertFalse($preview['projection_public_impact']['new_knowledge_minted']);
    }

    public function testRetirePreviewFailsClosedWithActiveEvidence(): void
    {
        $claim = new KnowledgeClaim(self::ID, 'nhk:test:repair', 'Internal workflow text', 'fact', ['origin' => 'TEST'], true, 1);
        $sourceId = '01a09786-dd67-70e7-9d30-9b8d3931766e';
        $evidenceId = '01a09786-dd67-70e7-9d30-9b8d39317670';
        $claims = $this->createMock(KnowledgeRepository::class);
        $claims->method('findByCanonicalId')->willReturn($claim);
        $evidence = $this->createMock(EvidenceRepository::class);
        $evidence->method('listByClaim')->willReturn([new Evidence($evidenceId, self::ID, $sourceId, 'supports', 'evidence', null, true, 1, [])]);
        $preview = (new KnowledgeRepairPreviewService($claims, $evidence))->preview(['canonical_knowledge_uuid' => self::ID, 'expected_revision' => 1, 'operation' => 'retire', 'reason' => 'cleanup', 'provenance' => ['origin' => 'TEST'], 'cleanup_class' => 'INTERNAL_WORKFLOW_KNOWLEDGE']);
        self::assertSame('REVIEW_REQUIRED', $preview['status']);
        self::assertContains('KNOWLEDGE_REPAIR_DEPENDENCY_REVIEW_REQUIRED', $preview['blockers']);
    }
}
