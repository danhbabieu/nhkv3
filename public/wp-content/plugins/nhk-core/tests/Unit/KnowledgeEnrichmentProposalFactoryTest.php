<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Knowledge\KnowledgeEnrichmentProposalFactory;
use NHK\Core\Application\Knowledge\KnowledgeEnrichmentProposalException;
use NHK\Core\Application\Governance\OperationCompatibility;
use NHK\Core\Domain\Knowledge\{KnowledgeEnrichmentCandidate, KnowledgeFacetProfile};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;
final class KnowledgeEnrichmentProposalFactoryTest extends TestCase
{
    public function test_candidate_uses_registered_create_operation_without_fabricating_revision(): void
    {
        $candidate = new KnowledgeEnrichmentCandidate('new_claim', UuidCodec::newV7(), new KnowledgeFacetProfile('music', 'movement'), 'Sonodo được ghi nhận trong ngữ cảnh máy 24.');
        $args = (new KnowledgeEnrichmentProposalFactory(['create']))->arguments($candidate, 'run-1');
        self::assertSame('create', $args['operation']); self::assertSame('knowledge', $args['entity_type']); self::assertNull($args['expected_revision']); self::assertSame($args['idempotency_key'], (new KnowledgeEnrichmentProposalFactory(['create']))->arguments($candidate, 'run-1')['idempotency_key']); self::assertStringContainsString('Sonodo', $args['payload']['text']); self::assertSame('movement', $args['payload']['provenance']['metadata']['scope']);
    }

    public function test_same_text_different_subject_or_scope_has_different_idempotency(): void
    {
        $factory = new KnowledgeEnrichmentProposalFactory(); $text = 'Cọc trắng.';
        $a = new KnowledgeEnrichmentCandidate('new_claim', UuidCodec::newV7(), new KnowledgeFacetProfile('recognition', 'variant'), $text);
        $b = new KnowledgeEnrichmentCandidate('new_claim', UuidCodec::newV7(), new KnowledgeFacetProfile('recognition', 'model'), $text);
        self::assertNotSame($factory->arguments($a, 'run')['idempotency_key'], $factory->arguments($b, 'run')['idempotency_key']);
    }

    public function test_evidence_candidate_maps_to_evidence_operation_without_claim_creation(): void
    {
        $candidate = new KnowledgeEnrichmentCandidate('add_evidence', UuidCodec::newV7(), new KnowledgeFacetProfile('recognition', 'variant'), 'Ảnh xác nhận.', ['claim_id' => UuidCodec::newV7(), 'source_id' => UuidCodec::newV7(), 'relation' => 'supports']);
        $args = (new KnowledgeEnrichmentProposalFactory())->arguments($candidate, 'run');
        self::assertSame('evidence', $args['entity_type']); self::assertSame('ingest', $args['operation']); self::assertNull($args['expected_revision']); self::assertSame([$args['payload']['claim_id'], $args['payload']['source_id']], $args['dependency_ids']); self::assertArrayNotHasKey('claim_text', $args['payload']);
    }

    public function test_evidence_candidate_without_source_is_not_mapped(): void
    {
        $candidate = new KnowledgeEnrichmentCandidate('add_evidence', UuidCodec::newV7(), new KnowledgeFacetProfile('recognition', 'variant'), 'Ảnh xác nhận.', ['claim_id' => UuidCodec::newV7(), 'relation' => 'supports']);
        $this->expectException(KnowledgeEnrichmentProposalException::class);
        $this->expectExceptionMessage('UNSUPPORTED');
        (new KnowledgeEnrichmentProposalFactory())->arguments($candidate, 'run');
    }

    public function test_missing_registered_operation_is_typed_registry_gap(): void
    {
        $candidate = new KnowledgeEnrichmentCandidate('new_claim', UuidCodec::newV7(), new KnowledgeFacetProfile('music', 'movement'), 'Cọc trắng.');
        try { (new KnowledgeEnrichmentProposalFactory([]))->arguments($candidate, 'run'); self::fail('Expected a registry gap.'); }
        catch (KnowledgeEnrichmentProposalException $error) { self::assertSame('REGISTRY_GAP', $error->diagnosticCode); }
    }

    public function test_evidence_idempotency_binds_source_and_relation(): void
    {
        $claim = UuidCodec::newV7(); $source = UuidCodec::newV7();
        $factory = new KnowledgeEnrichmentProposalFactory(['ingest']);
        $base = fn (string $relation, ?string $sourceId = null) => new KnowledgeEnrichmentCandidate('add_evidence', UuidCodec::newV7(), new KnowledgeFacetProfile('recognition', 'variant'), 'Cọc trắng.', ['claim_id' => $claim, 'source_id' => $sourceId ?? $source, 'relation' => $relation]);
        self::assertNotSame($factory->arguments($base('supports'), 'run')['idempotency_key'], $factory->arguments($base('contradicts'), 'run')['idempotency_key']);
        self::assertNotSame($factory->arguments($base('supports'), 'run')['idempotency_key'], $factory->arguments($base('supports', UuidCodec::newV7()), 'run')['idempotency_key']);
    }

    public function test_global_ingest_does_not_override_entity_specific_create_support(): void
    {
        $candidate = new KnowledgeEnrichmentCandidate('new_claim', UuidCodec::newV7(), new KnowledgeFacetProfile('music', 'movement'), 'Cọc trắng.');
        $policy = new class implements OperationCompatibility { public function supports(string $entityType, string $operation): bool { return $entityType === 'knowledge' && $operation === 'create'; } };
        $args = (new KnowledgeEnrichmentProposalFactory(['ingest', 'create'], $policy))->arguments($candidate, 'run');
        self::assertSame('create', $args['operation']);
    }

    public function test_evidence_selects_create_when_ingest_is_not_entity_supported(): void
    {
        $candidate = new KnowledgeEnrichmentCandidate('add_evidence', UuidCodec::newV7(), new KnowledgeFacetProfile('recognition', 'variant'), 'Ảnh xác nhận.', ['claim_id' => UuidCodec::newV7(), 'source_id' => UuidCodec::newV7(), 'relation' => 'supports']);
        $policy = new class implements OperationCompatibility { public function supports(string $entityType, string $operation): bool { return $entityType === 'evidence' && $operation === 'create'; } };
        $args = (new KnowledgeEnrichmentProposalFactory(['ingest', 'create'], $policy))->arguments($candidate, 'run');
        self::assertSame('create', $args['operation']);
    }

    public function test_entity_specific_ingest_is_selected_only_for_the_entity_that_supports_it(): void
    {
        $policy = new class implements OperationCompatibility { public function supports(string $entityType, string $operation): bool { return $entityType === 'knowledge' && $operation === 'ingest'; } };
        $claim = new KnowledgeEnrichmentCandidate('new_claim', UuidCodec::newV7(), new KnowledgeFacetProfile('music', 'movement'), 'Cọc trắng.');
        $evidence = new KnowledgeEnrichmentCandidate('add_evidence', UuidCodec::newV7(), new KnowledgeFacetProfile('recognition', 'variant'), 'Ảnh xác nhận.', ['claim_id' => UuidCodec::newV7(), 'source_id' => UuidCodec::newV7(), 'relation' => 'supports']);
        self::assertSame('ingest', (new KnowledgeEnrichmentProposalFactory(['ingest', 'create'], $policy))->arguments($claim, 'run')['operation']);
        try { (new KnowledgeEnrichmentProposalFactory(['ingest', 'create'], $policy))->arguments($evidence, 'run'); self::fail('Expected a registry gap.'); }
        catch (KnowledgeEnrichmentProposalException $error) { self::assertSame('REGISTRY_GAP', $error->diagnosticCode); }
    }

    public function test_global_operation_without_entity_support_is_never_selected(): void
    {
        $candidate = new KnowledgeEnrichmentCandidate('new_claim', UuidCodec::newV7(), new KnowledgeFacetProfile('music', 'movement'), 'Cọc trắng.');
        $policy = new class implements OperationCompatibility { public function supports(string $entityType, string $operation): bool { return false; } };
        $this->expectException(KnowledgeEnrichmentProposalException::class);
        $this->expectExceptionMessage('REGISTRY_GAP');
        (new KnowledgeEnrichmentProposalFactory(['ingest', 'create'], $policy))->arguments($candidate, 'run');
    }
}
