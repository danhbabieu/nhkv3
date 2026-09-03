<?php
declare(strict_types=1);
namespace NHK\Core\Application\Knowledge;
use NHK\Core\Domain\Knowledge\KnowledgeEnrichmentCandidate;
final class KnowledgeEnrichmentProposalFactory
{
    public function arguments(KnowledgeEnrichmentCandidate $candidate, string $operationId): array
    {
        $operations = ['knowledge' => 'ingest', 'evidence' => 'ingest'];
        $fingerprint = hash('sha256', json_encode([$candidate->subjectId, $candidate->profile->toMetadata(), $candidate->observation, $candidate->classification, $candidate->provenance], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($candidate->classification === 'new_claim') return ['operation' => $operations['knowledge'], 'entity_type' => 'knowledge', 'subject_id' => $candidate->subjectId, 'expected_revision' => 1, 'idempotency_key' => $operationId . ':knowledge:' . $fingerprint, 'payload' => ['stable_key' => 'nhk:knowledge:' . $fingerprint, 'text' => $candidate->observation, 'claim_type' => 'fact', 'provenance' => ['metadata' => $candidate->profile->toMetadata()] + $candidate->provenance]];
        if (in_array($candidate->classification, ['add_evidence', 'qualify', 'contradict'], true) && ($candidate->provenance['claim_id'] ?? '') !== '' && ($candidate->provenance['source_id'] ?? '') !== '') return ['operation' => $operations['evidence'], 'entity_type' => 'evidence', 'subject_id' => $candidate->subjectId, 'expected_revision' => max(1, (int) ($candidate->provenance['target_revision'] ?? 1)), 'idempotency_key' => $operationId . ':evidence:' . $fingerprint, 'payload' => ['claim_id' => $candidate->provenance['claim_id'], 'source_id' => $candidate->provenance['source_id'], 'excerpt' => $candidate->observation, 'relation' => $candidate->provenance['relation']]];
        throw new \InvalidArgumentException('Enrichment candidate requires governed review before proposal mapping.');
    }
}
