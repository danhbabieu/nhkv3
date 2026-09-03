<?php
declare(strict_types=1);
namespace NHK\Core\Application\Knowledge;
use NHK\Core\Domain\Knowledge\KnowledgeEnrichmentCandidate;
final class KnowledgeEnrichmentProposalFactory
{
    public function arguments(KnowledgeEnrichmentCandidate $candidate, string $operationId): array
    {
        if ($candidate->classification !== 'new_claim') throw new \InvalidArgumentException('Only new claim candidates can be proposed by this factory.');
        $fingerprint = hash('sha256', $candidate->observation);
        return ['operation' => 'ingest', 'entity_type' => 'knowledge', 'subject_id' => $candidate->subjectId, 'expected_revision' => 1, 'idempotency_key' => $operationId . ':knowledge:' . $fingerprint, 'payload' => ['claim_text' => $candidate->observation, 'claim_type' => 'fact', 'provenance' => $candidate->provenance, 'metadata' => $candidate->profile->toMetadata()]];
    }
}
