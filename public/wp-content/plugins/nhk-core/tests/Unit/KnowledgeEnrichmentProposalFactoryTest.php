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
        self::assertSame('ingest', $args['operation']); self::assertSame('knowledge', $args['entity_type']); self::assertSame('run-1:knowledge:' . hash('sha256', $candidate->observation), $args['idempotency_key']); self::assertSame('movement', $args['payload']['metadata']['scope']);
    }
}
