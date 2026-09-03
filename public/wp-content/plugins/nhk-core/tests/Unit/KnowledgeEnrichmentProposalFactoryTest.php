<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Knowledge\KnowledgeEnrichmentProposalFactory;
use NHK\Core\Domain\Knowledge\{KnowledgeEnrichmentCandidate, KnowledgeFacetProfile};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;
final class KnowledgeEnrichmentProposalFactoryTest extends TestCase
{
    public function test_candidate_becomes_existing_governed_ingest_arguments(): void
    {
        $candidate = new KnowledgeEnrichmentCandidate('new_claim', UuidCodec::newV7(), new KnowledgeFacetProfile('music', 'movement'), 'Sonodo được ghi nhận trong ngữ cảnh máy 24.');
        $args = (new KnowledgeEnrichmentProposalFactory())->arguments($candidate, 'run-1');
        self::assertSame('ingest', $args['operation']); self::assertSame('knowledge', $args['entity_type']); self::assertSame($args['idempotency_key'], (new KnowledgeEnrichmentProposalFactory())->arguments($candidate, 'run-1')['idempotency_key']); self::assertStringContainsString('Sonodo', $args['payload']['text']); self::assertSame('movement', $args['payload']['provenance']['metadata']['scope']);
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
        self::assertSame('evidence', $args['entity_type']); self::assertSame('ingest', $args['operation']); self::assertArrayNotHasKey('claim_text', $args['payload']);
    }
}
