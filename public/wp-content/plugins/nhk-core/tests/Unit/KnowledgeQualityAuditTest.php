<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\KnowledgeQualityAuditCoordinator;
use NHK\Core\Application\Knowledge\KnowledgeQualityAuditor;
use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class KnowledgeQualityAuditTest extends TestCase
{
    private string $subject;

    protected function setUp(): void
    {
        $this->subject = UuidCodec::newV7();
    }

    public function test_auditor_classifies_process_and_derived_contamination_without_mutation(): void
    {
        $claim = $this->claim('process', "Hệ thống resolve X và bài viết được tạo từ kết quả chuẩn hóa.", [
            'metadata' => [
                'subject_id' => $this->subject,
                'subject_type' => 'variant',
                'facet' => 'recognition',
                'scope' => 'variant',
                'provenance_class' => 'SYSTEM_INFERENCE',
                'lineage' => ['derived' => true, 'parent_claim_ids' => ['parent-1']],
            ],
        ]);
        $claims = new InMemoryClaims([$claim]);
        $result = (new KnowledgeQualityAuditor($claims, new InMemoryEvidence(), new InMemorySources(), new StructuredSemanticInterpreter()))->audit($claim);

        self::assertContains('PROCESS_CONTAMINATION', $result->findings);
        self::assertContains('DERIVED_CONTENT_CONTAMINATION', $result->findings);
        self::assertSame(1, $claim->revision);
        self::assertCount(1, $claims->list());
    }

    public function test_auditor_retires_source_locators_and_instructions_instead_of_requesting_evidence(): void
    {
        $sourceLocator = $this->claim('source-locator', 'https://example.test/westminster', [
            'origin' => 'EXPLICIT_USER_KNOWLEDGE',
            'metadata' => ['subject_id' => $this->subject, 'subject_type' => 'music', 'facet' => 'history', 'scope' => 'entity'],
        ]);
        $instruction = $this->claim('instruction', 'Bổ sung Evidence cho nguồn Westminster.', [
            'origin' => 'EXPLICIT_USER_KNOWLEDGE',
            'metadata' => ['subject_id' => $this->subject, 'subject_type' => 'music', 'facet' => 'history', 'scope' => 'entity'],
        ]);
        $claims = new InMemoryClaims([$sourceLocator, $instruction]);
        $auditor = new KnowledgeQualityAuditor($claims, new InMemoryEvidence(), new InMemorySources(), new StructuredSemanticInterpreter());

        $sourceResult = $auditor->audit($sourceLocator);
        $instructionResult = $auditor->audit($instruction);

        self::assertContains('PROCESS_CONTAMINATION', $sourceResult->findings);
        self::assertNotContains('ADD_EVIDENCE', array_column($sourceResult->repairCandidates, 'action'));
        self::assertContains('RETIRE_PROCESS_CONTAMINATION_REVIEW', array_column($sourceResult->repairCandidates, 'action'));
        self::assertContains('INTERNAL_WORKFLOW_KNOWLEDGE', $instructionResult->findings);
        self::assertNotContains('ADD_EVIDENCE', array_column($instructionResult->repairCandidates, 'action'));
        self::assertContains('RETIRE_INTERNAL_WORKFLOW_REVIEW', array_column($instructionResult->repairCandidates, 'action'));
    }

    public function test_auditor_classifies_contextual_westminster_workflow_fragments_without_evidence_repairs(): void
    {
        $claims = [
            $this->claim('source-note', "Source: https://www.greatstmarys.org/bells (Bells, Great St Mary's).", [
                'origin' => 'EXPLICIT_USER_KNOWLEDGE',
                'metadata' => ['subject_id' => $this->subject, 'subject_type' => 'music', 'facet' => 'history', 'scope' => 'entity'],
            ]),
            $this->claim('governance-note', 'Tách thành claim nguyên tử, tái sử dụng Source/Knowledge đã có, giữ Source/Evidence theo scope.', [
                'origin' => 'EXPLICIT_USER_KNOWLEDGE',
                'metadata' => ['subject_id' => $this->subject, 'subject_type' => 'music', 'facet' => 'history', 'scope' => 'entity'],
            ]),
        ];
        $auditor = new KnowledgeQualityAuditor(new InMemoryClaims($claims), new InMemoryEvidence(), new InMemorySources(), new StructuredSemanticInterpreter());

        $sourceResult = $auditor->audit($claims[0]);
        $instructionResult = $auditor->audit($claims[1]);

        self::assertContains('PROCESS_CONTAMINATION', $sourceResult->findings);
        self::assertNotContains('ADD_EVIDENCE', array_column($sourceResult->repairCandidates, 'action'));
        self::assertContains('INTERNAL_WORKFLOW_KNOWLEDGE', $instructionResult->findings);
        self::assertNotContains('ADD_EVIDENCE', array_column($instructionResult->repairCandidates, 'action'));
    }

    public function test_auditor_accepts_capture_origin_as_provenance_class(): void
    {
        $claim = $this->claim('research-origin', 'Joseph Jowett được ghi nhận trong một nghiên cứu lịch sử.', [
            'origin' => 'EXTERNAL_RESEARCH',
            'metadata' => ['subject_id' => $this->subject, 'subject_type' => 'music', 'facet' => 'history', 'scope' => 'entity'],
        ]);

        $result = (new KnowledgeQualityAuditor(new InMemoryClaims([$claim]), new InMemoryEvidence(), new InMemorySources(), new StructuredSemanticInterpreter()))->audit($claim);

        self::assertSame('EXTERNAL_RESEARCH', $result->provenanceAssessment['class']);
        self::assertNotContains('PROVENANCE_GAP', $result->findings);
    }

    public function test_auditor_separates_exact_duplicate_from_same_claim_with_new_evidence(): void
    {
        $first = $this->claim('first', 'Variant A dùng bộ máy M.', ['metadata' => ['subject_id' => $this->subject, 'subject_type' => 'variant', 'facet' => 'movement', 'scope' => 'variant', 'provenance_class' => 'CATALOG_SUPPORTED']]);
        $second = $this->claim('second', 'Variant A dùng bộ máy M.', ['metadata' => ['subject_id' => $this->subject, 'subject_type' => 'variant', 'facet' => 'movement', 'scope' => 'variant', 'provenance_class' => 'CATALOG_SUPPORTED']]);
        $source = new Source(UuidCodec::newV7(), 'catalog.audit', 'Audit catalog', 'catalog', 'https://example.test/catalog', ['visibility' => 'PUBLIC'], true, 1);
        $evidence = new Evidence(UuidCodec::newV7(), $first->canonicalId, $source->canonicalId, 'supports', 'Catalog excerpt', null, true, 1, ['visibility' => 'PUBLIC']);
        $auditor = new KnowledgeQualityAuditor(new InMemoryClaims([$first, $second]), new InMemoryEvidence([$evidence]), new InMemorySources([$source]), new StructuredSemanticInterpreter());

        $result = $auditor->audit($second);

        self::assertSame('EXACT_DUPLICATE', $result->duplicateMatches[0]['classification']);
        self::assertContains($first->canonicalId, $result->reuseCandidates);
        self::assertNotContains('EVIDENCE_GAP', $result->findings);
    }

    public function test_auditor_reports_scope_provenance_evidence_and_atomization_findings(): void
    {
        $claim = $this->claim('wide', 'X có A, dùng B, sản xuất năm C và thường gặp tại D.', [
            'metadata' => [
                'subject_id' => $this->subject,
                'subject_type' => 'specimen',
                'facet' => 'configuration',
                'scope' => 'brand',
                'provenance_class' => 'LEGACY_UNKNOWN',
            ],
        ]);
        $result = (new KnowledgeQualityAuditor(new InMemoryClaims([$claim]), new InMemoryEvidence(), new InMemorySources(), new StructuredSemanticInterpreter()))->audit($claim);

        self::assertContains('SCOPE_PROBLEM', $result->findings);
        self::assertContains('PROVENANCE_GAP', $result->findings);
        self::assertContains('EVIDENCE_GAP', $result->findings);
        self::assertContains('ATOMIZATION_NEEDED', $result->findings);
        self::assertCount(3, $result->repairCandidates);
    }

    public function test_batch_coordinator_is_bounded_and_returns_resume_cursor(): void
    {
        $claims = [];
        for ($i = 0; $i < 3; $i++) {
            $claims[] = $this->claim('batch-' . $i, 'Claim ' . $i, ['metadata' => ['subject_id' => $this->subject, 'subject_type' => 'variant', 'facet' => 'identity', 'scope' => 'variant', 'provenance_class' => 'EXPLICIT_USER_KNOWLEDGE']]);
        }
        $repository = new InMemoryClaims($claims);
        $coordinator = new KnowledgeQualityAuditCoordinator(new KnowledgeQualityAuditor($repository, new InMemoryEvidence(), new InMemorySources(), new StructuredSemanticInterpreter()), $repository);

        $page = $coordinator->auditBatch(2);

        self::assertCount(2, $page['results']);
        self::assertTrue($page['has_more']);
        self::assertSame('nhk:quality:batch-1', $page['next_cursor']);
        self::assertSame(3, count($repository->list()));
        self::assertFalse($page['mutated']);
    }

    public function test_interpreter_candidates_remain_review_only_and_default_report_redacts_private_text(): void
    {
        $target = UuidCodec::newV7();
        $claim = $this->claim('candidate', 'Alpha 36 dùng bộ máy M.', ['metadata' => [
            'subject_id' => $this->subject,
            'subject_type' => 'variant',
            'facet' => 'configuration',
            'scope' => 'variant',
            'provenance_class' => 'EXPLICIT_USER_KNOWLEDGE',
            'relation_candidates' => [['source_id' => $this->subject, 'target_id' => $target, 'predicate' => 'configured_with_music', 'scope' => 'variant']],
        ]]);
        $registry = new PredicateRegistry();
        $interpreter = new StructuredSemanticInterpreter(null, static function (string $predicate) use ($registry): bool {
            try { $registry->get($predicate); return true; } catch (\Throwable) { return false; }
        });
        $source = new Source(UuidCodec::newV7(), 'catalog.relation-audit', 'Relation audit catalog', 'catalog', 'https://example.test/relation', ['visibility' => 'PUBLIC'], true, 1);
        $evidence = new Evidence(UuidCodec::newV7(), $claim->canonicalId, $source->canonicalId, 'supports', 'Relation audit excerpt', null, true, 1, ['visibility' => 'PUBLIC']);
        $result = (new KnowledgeQualityAuditor(new InMemoryClaims([$claim]), new InMemoryEvidence([$evidence]), new InMemorySources([$source]), $interpreter))->audit($claim);
        $safe = $result->toArray();

        self::assertSame('RELATION_CANDIDATE', $result->relationCandidates[0]['predicate'] !== '' ? 'RELATION_CANDIDATE' : '');
        self::assertContains('RELATION_CANDIDATE', $result->findings);
        self::assertNotEmpty(array_filter($result->repairCandidates, static fn (array $candidate): bool => $candidate['action'] === 'RELATION_REVIEW' && $candidate['planning_only'] === true));
        self::assertArrayNotHasKey('claim_candidates', $safe['structured_interpretation']);
        self::assertArrayNotHasKey('raw_input_reference', $safe['structured_interpretation']);
    }

    private function claim(string $key, string $text, array $provenance): KnowledgeClaim
    {
        return new KnowledgeClaim(UuidCodec::newV7(), 'nhk:quality:' . $key, $text, 'fact', $provenance);
    }
}

final class InMemoryClaims implements KnowledgeRepository
{
    public function __construct(private array $items) {}
    public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $stableKey): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $stableKey) return $item; return null; }
    public function create(KnowledgeClaim $claim): KnowledgeClaim { throw new \LogicException('audit repository is read-only'); }
    public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { throw new \LogicException('audit repository is read-only'); }
    public function list(bool $includeRetired = false): array { return $this->items; }
}

final class InMemoryEvidence implements EvidenceRepository
{
    public function __construct(private array $items = []) {}
    public function findByCanonicalId(string $id): ?Evidence { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function create(Evidence $evidence): Evidence { throw new \LogicException('audit repository is read-only'); }
    public function update(Evidence $evidence, int $expectedRevision): Evidence { throw new \LogicException('audit repository is read-only'); }
    public function listByClaim(string $claimId, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (Evidence $item): bool => $item->claimId === $claimId)); }
    public function listBySource(string $sourceId, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (Evidence $item): bool => $item->sourceId === $sourceId)); }
}

final class InMemorySources implements SourceRepository
{
    public function __construct(private array $items = []) {}
    public function findByCanonicalId(string $id): ?Source { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $stableKey): ?Source { foreach ($this->items as $item) if ($item->stableKey === $stableKey) return $item; return null; }
    public function create(Source $source): Source { throw new \LogicException('audit repository is read-only'); }
    public function update(Source $source, int $expectedRevision): Source { throw new \LogicException('audit repository is read-only'); }
    public function list(bool $includeRetired = false): array { return $this->items; }
}
