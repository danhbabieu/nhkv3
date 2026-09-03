<?php
declare(strict_types=1);
namespace NHK\Core\Application\Knowledge;
use NHK\Core\Application\Governance\{ControlledApplyOperationRegistry, OperationCompatibility};
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Domain\Knowledge\KnowledgeEnrichmentCandidate;

/** Translates a validated candidate into the existing generic proposal envelope. */
final class KnowledgeEnrichmentProposalFactory
{
    /** @param list<string>|null $supportedOperations */
    public function __construct(private ?array $supportedOperations = null, private ?OperationCompatibility $operationCompatibility = null) {}

    /** @return array<string,mixed> */
    public function arguments(KnowledgeEnrichmentCandidate $candidate, string $operationId): array
    {
        $isClaim = $candidate->classification === 'new_claim';
        $payload = $this->payload($candidate);
        $operation = $this->operationFor($isClaim ? 'knowledge' : 'evidence');
        $dependencies = array_values(array_filter([$candidate->provenance['claim_id'] ?? null, $candidate->provenance['source_id'] ?? null], static fn (mixed $id): bool => is_string($id) && $id !== ''));
        $dependencyRevisions = array_filter([
            (string) ($candidate->provenance['claim_id'] ?? '') => $candidate->provenance['claim_revision'] ?? null,
            (string) ($candidate->provenance['source_id'] ?? '') => $candidate->provenance['source_revision'] ?? null,
        ], static fn (mixed $revision): bool => is_int($revision) && $revision > 0);
        if ($dependencyRevisions !== []) $payload['dependency_revisions'] = $dependencyRevisions;
        $profileMetadata = $candidate->profile->toMetadata();
        $binding = ['subject_id' => $candidate->subjectId, 'facet' => $candidate->profile->facet, 'scope' => $candidate->profile->scope, 'profile_version' => $profileMetadata['version'], 'classification' => $candidate->classification, 'operation' => $operation, 'payload' => $payload, 'dependency_ids' => $dependencies];
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize($binding));
        return ['operation' => $operation, 'entity_type' => $isClaim ? 'knowledge' : 'evidence', 'subject_id' => $candidate->subjectId, 'target_uuid' => null, 'expected_revision' => null, 'dependency_ids' => $dependencies, 'content_fingerprint' => $fingerprint, 'dependency_fingerprint' => hash('sha256', CommandCanonicalizer::canonicalize(['claim_id' => $candidate->provenance['claim_id'] ?? null, 'source_id' => $candidate->provenance['source_id'] ?? null, 'claim_revision' => $candidate->provenance['claim_revision'] ?? null, 'source_revision' => $candidate->provenance['source_revision'] ?? null])), 'idempotency_key' => $operationId . ':' . ($isClaim ? 'knowledge' : 'evidence') . ':' . $fingerprint, 'payload' => $payload];
    }

    private function operationFor(string $entityType): string
    {
        $operations = $this->supportedOperations ?? \NHK\Core\Application\Mcp\McpToolCatalog::governedOperations();
        $compatibility = $this->operationCompatibility ?? new ControlledApplyOperationRegistry();
        foreach (['ingest', 'create'] as $operation) if (in_array($operation, $operations, true) && $compatibility->supports($entityType, $operation)) return $operation;
        throw new KnowledgeEnrichmentProposalException('REGISTRY_GAP', 'No registered create operation for ' . $entityType . '.');
    }

    /** @return array<string,mixed> */
    private function payload(KnowledgeEnrichmentCandidate $candidate): array
    {
        if ($candidate->classification === 'new_claim') return ['stable_key' => 'nhk:knowledge:' . hash('sha256', CommandCanonicalizer::canonicalize([$candidate->subjectId, $candidate->profile->toMetadata(), $candidate->observation])), 'text' => $candidate->observation, 'claim_type' => (string) ($candidate->provenance['claim_type'] ?? 'fact'), 'provenance' => ['metadata' => $candidate->profile->toMetadata()] + $candidate->provenance];
        if (!in_array($candidate->classification, ['add_evidence', 'qualify', 'contradict'], true)) throw new KnowledgeEnrichmentProposalException('UNSUPPORTED', 'Candidate classification cannot become a proposal.');
        $claimId = (string) ($candidate->provenance['claim_id'] ?? ''); $sourceId = (string) ($candidate->provenance['source_id'] ?? '');
        if ($claimId === '' || $sourceId === '') throw new KnowledgeEnrichmentProposalException('UNSUPPORTED', 'Evidence candidate requires resolved claim_id and source_id.');
        return ['claim_id' => $claimId, 'source_id' => $sourceId, 'excerpt' => $candidate->observation, 'relation' => (string) ($candidate->provenance['relation'] ?? ''), 'locator' => $candidate->provenance['locator'] ?? null, 'metadata' => is_array($candidate->provenance['metadata'] ?? null) ? $candidate->provenance['metadata'] : $candidate->profile->toMetadata()];
    }
}
