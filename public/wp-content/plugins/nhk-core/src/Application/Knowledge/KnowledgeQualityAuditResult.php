<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Domain\Knowledge\KnowledgeClaim;

/** Ephemeral, read-only audit output. It has no persistence or apply method. */
final readonly class KnowledgeQualityAuditResult
{
    /** @param array<string,mixed> $subjectResolution @param array<string,mixed> $scopeAssessment @param array<string,mixed> $provenanceAssessment @param array<string,mixed> $evidenceAssessment @param array<string,mixed> $structuredInterpretation @param list<array<string,mixed>> $duplicateMatches @param list<string> $reuseCandidates @param list<string> $findings @param list<array<string,mixed>> $repairCandidates @param list<array<string,mixed>> $dictionaryCandidates @param list<array<string,mixed>> $relationCandidates @param array<string,mixed> $facetClassification @param array<string,mixed> $writerReadiness @param list<string> $diagnostics @param array<string,mixed> $identityResolution */
    public function __construct(
        public string $knowledgeId,
        public int $revision,
        public array $subjectResolution,
        public array $scopeAssessment,
        public array $provenanceAssessment,
        public array $evidenceAssessment,
        public array $structuredInterpretation,
        public array $duplicateMatches,
        public array $reuseCandidates,
        public array $findings,
        public array $repairCandidates,
        public array $dictionaryCandidates,
        public array $relationCandidates,
        public array $facetClassification,
        public array $writerReadiness,
        public array $diagnostics,
        public array $identityResolution = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(bool $includePrivate = false): array
    {
        $result = [
            'knowledge_id' => $this->knowledgeId,
            'revision' => $this->revision,
            'subject_resolution' => $this->subjectResolution,
            'scope_assessment' => $this->scopeAssessment,
            'provenance_assessment' => $this->provenanceAssessment,
            'evidence_assessment' => $this->evidenceAssessment,
            'structured_interpretation' => $this->structuredInterpretation,
            'duplicate_matches' => $this->duplicateMatches,
            'reuse_candidates' => $this->reuseCandidates,
            'quality_findings' => $this->findings,
            'repair_candidates' => $this->repairCandidates,
            'dictionary_candidates' => $this->dictionaryCandidates,
            'relation_candidates' => $this->relationCandidates,
            'facet_classification' => $this->facetClassification,
            'writer_readiness' => $this->writerReadiness,
            'diagnostics' => $this->diagnostics,
            'identity_resolution' => $this->identityResolution,
        ];
        if (!$includePrivate) {
            $subject = $result['subject_resolution'];
            $result['subject_resolution'] = [
                'status' => $subject['status'] ?? 'unresolved',
                'canonical_subject_id' => $subject['canonical_subject_id'] ?? '',
                'entity_type' => $subject['entity_type'] ?? '',
                'candidate_count' => count((array) ($subject['candidates'] ?? [])),
                'facet' => $subject['facet'] ?? '',
                'scope' => $subject['scope'] ?? '',
            ];
            $structured = $result['structured_interpretation'];
            $result['structured_interpretation'] = [
                'status' => $structured['status'] ?? 'UNRESOLVED',
                'source_context' => [
                    'source_kind' => $structured['source_context']['source_kind'] ?? 'generic',
                    'raw_or_derived' => $structured['source_context']['raw_or_derived'] ?? 'RAW',
                    'content_intent' => $structured['source_context']['content_intent'] ?? '',
                ],
                'counts' => [
                    'lexical_spans' => count((array) ($structured['lexical_spans'] ?? [])),
                    'resolved_references' => count((array) ($structured['resolved_references'] ?? [])),
                    'unresolved_terms' => count((array) ($structured['unresolved_terms'] ?? [])),
                    'ambiguous_terms' => count((array) ($structured['ambiguous_terms'] ?? [])),
                    'claim_candidates' => count((array) ($structured['claim_candidates'] ?? [])),
                    'dictionary_candidates' => count((array) ($structured['dictionary_delta_candidates'] ?? [])),
                    'relation_candidates' => count((array) ($structured['relation_candidates'] ?? [])),
                ],
                'diagnostics' => $structured['diagnostics'] ?? [],
                'outcomes' => $structured['outcomes'] ?? [],
            ];
            $result['evidence_assessment']['private_evidence_count'] = $result['evidence_assessment']['private_evidence_count'] ?? 0;
            $result['dictionary_candidates'] = array_fill(0, count($result['dictionary_candidates']), ['planning_only' => true]);
            $result['relation_candidates'] = array_map(static fn (array $candidate): array => [
                'source_id' => (string) ($candidate['source_id'] ?? ''),
                'target_id' => (string) ($candidate['target_id'] ?? ''),
                'predicate' => (string) ($candidate['predicate'] ?? ''),
                'scope' => (string) ($candidate['scope'] ?? ''),
                'planning_only' => true,
            ], array_values(array_filter($result['relation_candidates'], 'is_array')));
        }
        return $result;
    }
}
