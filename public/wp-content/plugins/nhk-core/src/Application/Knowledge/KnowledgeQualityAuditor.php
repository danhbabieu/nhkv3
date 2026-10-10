<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Application\Semantic\{DerivedLineageGuard, StructuredSemanticInterpreter};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeQualityCandidateReader, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{CollectorFacetRegistry, KnowledgeClaim, KnowledgeFacetProfile};

/**
 * Performs a deterministic, ephemeral quality audit over one canonical Claim.
 * It never calls a mutating repository method and never turns a candidate into truth.
 */
final class KnowledgeQualityAuditor
{
    private const MAX_CANDIDATE_ROWS = 128;
    private const PROVENANCE = ['OBSERVED_FROM_MEDIA', 'EXPLICIT_USER_KNOWLEDGE', 'CATALOG_SUPPORTED', 'EXTERNAL_RESEARCH', 'SYSTEM_INFERENCE'];
    private const SCOPES = ['entity', 'brand', 'model', 'variant', 'movement', 'specimen', 'specimen_observation', 'observation', 'editorial_experience', 'hypothesis', 'unresolved'];

    /** @param callable(array<string,mixed>):array<string,mixed>|null $subjectResolver */
    public function __construct(
        private KnowledgeRepository $claims,
        private EvidenceRepository $evidence,
        private SourceRepository $sources,
        private StructuredSemanticInterpreter $interpreter,
        private $subjectResolver = null,
        private ?DerivedLineageGuard $lineageGuard = null,
    ) {
        $this->lineageGuard ??= new DerivedLineageGuard();
    }

    public function audit(KnowledgeClaim $claim): KnowledgeQualityAuditResult
    {
        $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
        $identity = KnowledgeClaimIdentity::resolveClaim($claim);
        $subject = $this->subject($metadata);
        $scope = strtolower(trim((string) ($metadata['scope'] ?? $subject['entity_type'] ?? '')));
        $facet = strtolower(trim((string) ($metadata['facet'] ?? $metadata['projection_category'] ?? '')));
        $provenance = strtoupper(trim((string) ($metadata['provenance_class'] ?? $metadata['source_class'] ?? $claim->provenance['source_class'] ?? $claim->provenance['origin'] ?? '')));
        $evidence = $this->evidenceAssessment($claim, $scope);
        $packet = $this->interpreter->interpret([
            'raw_text' => $claim->claimText,
            'owner_or_source_type' => (string) ($metadata['source_kind'] ?? $metadata['origin'] ?? 'KNOWLEDGE'),
            'metadata' => $metadata,
            'subject_resolution' => $subject,
            'provenance' => ['source_class' => $provenance],
            'lineage' => (array) ($metadata['lineage'] ?? []),
            'existing_knowledge' => $this->candidateRows($claim, $subject, $scope, $facet),
            'relations' => (array) ($metadata['relation_candidates'] ?? []),
        ]);
        $structured = $packet->toArray();
        $findings = [];
        $diagnostics = [];
        if (($subject['status'] ?? '') !== 'resolved') $findings[] = (($subject['status'] ?? '') === 'ambiguous' ? 'SUBJECT_AMBIGUOUS' : 'SUBJECT_UNRESOLVED');
        if (!in_array($scope, self::SCOPES, true) || (($subject['entity_type'] ?? '') === 'specimen' && $scope !== 'specimen_observation')) $findings[] = 'SCOPE_PROBLEM';
        if (!in_array($provenance, self::PROVENANCE, true)) $findings[] = 'PROVENANCE_GAP';
        $inputClass = $this->interpreter->classifySegment($claim->claimText)['class'] ?? 'SEMANTIC_OR_EDITORIAL';
        if ($inputClass === 'SOURCE_LOCATOR') $findings[] = 'PROCESS_CONTAMINATION';
        if ($inputClass === 'OPERATIONAL_INSTRUCTION') $findings[] = 'INTERNAL_WORKFLOW_KNOWLEDGE';
        if (!$this->lineageGuard->isIndependent(['source_kind' => $metadata['source_kind'] ?? 'KNOWLEDGE', 'lineage' => $metadata['lineage'] ?? []])) $findings[] = 'DERIVED_CONTENT_CONTAMINATION';
        if ($this->isProcessContamination($claim->claimText, $metadata)) $findings[] = 'PROCESS_CONTAMINATION';
        if ($this->isEditorialFragment($claim->claimText, $metadata)) $findings[] = 'EDITORIAL_FRAGMENT';
        if ($this->needsAtomization($claim->claimText)) $findings[] = 'ATOMIZATION_NEEDED';
        if ($facet === '' || (!in_array($facet, KnowledgeFacetProfile::FACETS, true) && !CollectorFacetRegistry::isValid($facet))) $diagnostics[] = 'FACET_UNRESOLVED';
        if ($this->claims instanceof KnowledgeQualityCandidateReader || method_exists($this->claims, 'qualityCandidates')) $diagnostics[] = 'QUALITY_CANDIDATES_BOUNDED';
        $matches = $this->duplicateMatches($claim, $subject, $scope, $facet);
        if ($evidence['status'] === 'MISSING' && $this->hasSupportedCanonicalMatch($matches)) {
            $evidence['status'] = 'SUPPORTED_BY_REUSABLE_CANONICAL';
            $evidence['reused_canonical_count'] = count($matches);
        }
        if (($evidence['status'] ?? '') === 'MISSING' || ($evidence['status'] ?? '') === 'INSUFFICIENT') $findings[] = 'EVIDENCE_GAP';
        foreach ($matches as $match) $findings[] = $match['classification'] === 'EXACT_DUPLICATE' ? 'DUPLICATE_OR_REUSE' : 'QUALIFICATION_REVIEW';
        $relationCandidates = ($scope !== '' && in_array($scope, self::SCOPES, true) && in_array($provenance, self::PROVENANCE, true) && $evidence['status'] === 'SUPPORTED_WITHIN_SCOPE') ? $structured['relation_candidates'] : [];
        if ((array) $structured['relation_candidates'] !== [] && $relationCandidates === []) $diagnostics[] = 'RELATION_CANDIDATE_REVIEW_REQUIRED';
        if ($relationCandidates !== []) $findings[] = 'RELATION_CANDIDATE';
        if ($structured['dictionary_delta_candidates'] !== []) $findings[] = 'DICTIONARY_CANDIDATE';
        $findings = array_values(array_unique($findings));
        if ($findings === []) $findings[] = 'KEEP_CANONICAL';
        $repairs = $this->repairs($findings);
        $quality = $findings === ['KEEP_CANONICAL'] ? ['status' => 'KEEP_CANONICAL'] : ['status' => 'REVIEW_REQUIRED'];
        return new KnowledgeQualityAuditResult(
            $claim->canonicalId,
            $claim->revision,
            $subject + ['facet' => $facet, 'scope' => $scope],
            ['scope' => $scope !== '' ? $scope : 'UNRESOLVED', 'narrowest_supported' => $this->narrowestScope($scope, $subject), 'status' => in_array('SCOPE_PROBLEM', $findings, true) ? 'REVIEW_REQUIRED' : 'VALID'],
            ['class' => $provenance !== '' ? $provenance : 'UNRESOLVED', 'status' => in_array('PROVENANCE_GAP', $findings, true) ? 'GAP' : 'VALID'],
            $evidence,
            $structured,
            $matches,
            array_values(array_map(static fn (array $match): string => (string) $match['knowledge_id'], array_filter($matches, static fn (array $match): bool => in_array($match['classification'], ['EXACT_DUPLICATE', 'SEMANTIC_REUSE', 'SAME_CLAIM_NEW_EVIDENCE'], true)))),
            $findings,
            $repairs,
            $structured['dictionary_delta_candidates'],
            $relationCandidates,
            ['subject_id' => (string) ($subject['canonical_subject_id'] ?? ''), 'facet' => $facet, 'covered' => $findings === ['KEEP_CANONICAL'] && $facet !== '', 'quality' => $quality],
            ['status' => $findings === ['KEEP_CANONICAL'] ? 'READY' : ($subject['status'] === 'resolved' ? 'PARTIAL' : 'BLOCKED'), 'eligible_claim' => $findings === ['KEEP_CANONICAL'], 'excluded_reasons' => $findings === ['KEEP_CANONICAL'] ? [] : $findings],
            $diagnostics,
            [
                'status' => $identity->status(),
                'reason_codes' => $identity->reasonCodes(),
                'missing_fields' => $identity->missingFields(),
                'identity_policy' => $identity->policyVersion(),
                'lifecycle_state' => $claim->active ? 'ACTIVE' : 'RETIRED',
                'source_class' => $this->sourceClass($metadata),
                'coverage_impact' => $identity->status() === KnowledgeClaimIdentityResolution::RESOLVED ? 'DUPLICATE_GROUPING_AVAILABLE' : 'DUPLICATE_GROUPING_EXCLUDED',
            ],
        );
    }

    /** @return array<string,mixed> */
    private function subject(array $metadata): array
    {
        $input = ['subject_id' => (string) ($metadata['subject_id'] ?? ''), 'subject_type' => (string) ($metadata['subject_type'] ?? ''), 'candidates' => (array) ($metadata['subject_candidates'] ?? [])];
        if ($this->subjectResolver !== null) {
            try { $resolved = ($this->subjectResolver)($input); if (is_array($resolved)) return $resolved; } catch (\Throwable) { return ['status' => 'unresolved', 'candidates' => $input['candidates']]; }
        }
        if ($input['subject_id'] === '') return ['status' => 'unresolved', 'candidates' => $input['candidates']];
        if (count($input['candidates']) > 1) return ['status' => 'ambiguous', 'candidates' => $input['candidates']];
        return ['status' => 'resolved', 'canonical_subject_id' => $input['subject_id'], 'entity_type' => $input['subject_type'], 'candidates' => $input['candidates']];
    }

    private function sourceClass(array $metadata): string
    {
        $sourceClass = strtoupper(trim((string) ($metadata['source_class'] ?? $metadata['provenance_class'] ?? '')));
        return in_array($sourceClass, self::PROVENANCE, true) ? $sourceClass : 'UNKNOWN';
    }

    /** @return array<string,mixed> */
    private function evidenceAssessment(KnowledgeClaim $claim, string $scope): array
    {
        $items = $this->evidence->listByClaim($claim->canonicalId, true);
        $supporting = 0; $private = 0; $sourceMissing = 0; $valid = [];
        foreach ($items as $item) {
            if (!$item->active) continue;
            if (!$item->isPublic()) $private++;
            $source = $this->sources->findByCanonicalId($item->sourceId);
            if ($source === null || !$source->active) { $sourceMissing++; continue; }
            if ($item->relation === 'supports') { $supporting++; $valid[] = ['evidence_id' => $item->canonicalId, 'revision' => $item->revision, 'source_id' => $source->canonicalId, 'source_revision' => $source->revision, 'relation' => $item->relation, 'scope' => $scope]; }
        }
        return ['status' => $supporting > 0 && $sourceMissing === 0 ? 'SUPPORTED_WITHIN_SCOPE' : ($items === [] ? 'MISSING' : 'INSUFFICIENT'), 'active_count' => count($items), 'supporting_count' => $supporting, 'private_evidence_count' => $private, 'source_missing_count' => $sourceMissing, 'references' => $valid];
    }

    /** @return list<array<string,mixed>> */
    private function duplicateMatches(KnowledgeClaim $claim, array $subject, string $scope, string $facet): array
    {
        $matches = [];
        $key = $this->propositionKey($claim->claimText);
        foreach ($this->candidateClaims($claim) as $other) {
            if (!$other instanceof KnowledgeClaim || $other->canonicalId === $claim->canonicalId) continue;
            $metadata = is_array($other->provenance['metadata'] ?? null) ? $other->provenance['metadata'] : [];
            if ((string) ($metadata['subject_id'] ?? '') !== (string) ($subject['canonical_subject_id'] ?? '') || strtolower((string) ($metadata['scope'] ?? '')) !== $scope || strtolower((string) ($metadata['facet'] ?? $metadata['projection_category'] ?? '')) !== $facet) continue;
            $otherKey = $this->propositionKey($other->claimText);
            if ($key !== $otherKey) continue;
            $classification = $claim->claimText === $other->claimText ? 'EXACT_DUPLICATE' : 'SEMANTIC_REUSE';
            $claimSources = array_map(static fn ($item): string => $item->sourceId, array_filter($this->evidence->listByClaim($claim->canonicalId, true), static fn ($item): bool => $item->active));
            $otherSources = array_map(static fn ($item): string => $item->sourceId, array_filter($this->evidence->listByClaim($other->canonicalId, true), static fn ($item): bool => $item->active));
            if ($claimSources !== [] && $otherSources !== [] && array_diff($claimSources, $otherSources) !== []) $classification = 'SAME_CLAIM_NEW_EVIDENCE';
            $matches[] = ['knowledge_id' => $other->canonicalId, 'revision' => $other->revision, 'classification' => $classification, 'subject_id' => $subject['canonical_subject_id'] ?? '', 'scope' => $scope, 'facet' => $facet, 'match_basis' => 'deterministic_subject_scope_facet_proposition'];
        }
        usort($matches, static fn (array $a, array $b): int => strcmp($a['knowledge_id'], $b['knowledge_id']));
        return $matches;
    }

    /** @param list<array<string,mixed>> $matches */
    private function hasSupportedCanonicalMatch(array $matches): bool
    {
        foreach ($matches as $match) {
            $other = $this->claims->findByCanonicalId((string) ($match['knowledge_id'] ?? ''));
            if ($other === null) continue;
            $supporting = array_filter($this->evidence->listByClaim($other->canonicalId, true), fn ($item): bool => $item->active && $item->relation === 'supports' && $this->sources->findByCanonicalId($item->sourceId)?->active === true);
            if ($supporting !== []) return true;
        }
        return false;
    }

    /** @return list<array<string,mixed>> */
    private function candidateRows(KnowledgeClaim $claim, array $subject, string $scope, string $facet): array
    {
        return array_map(static fn (KnowledgeClaim $item): array => ['claim_id' => $item->canonicalId, 'text' => $item->claimText, 'subject_id' => $subject['canonical_subject_id'] ?? '', 'scope' => $scope, 'facet' => $facet], $this->candidateClaims($claim));
    }

    /** @return list<KnowledgeClaim> */
    private function candidateClaims(KnowledgeClaim $claim): array
    {
        if ($this->claims instanceof KnowledgeQualityCandidateReader) return $this->claims->qualityCandidates($claim, self::MAX_CANDIDATE_ROWS);
        if (method_exists($this->claims, 'qualityCandidates')) {
            $candidates = $this->claims->qualityCandidates($claim, self::MAX_CANDIDATE_ROWS);
            return is_array($candidates) ? array_values(array_filter($candidates, static fn (mixed $candidate): bool => $candidate instanceof KnowledgeClaim)) : [];
        }
        return array_values(array_filter(array_slice($this->claims->list(true), 0, self::MAX_CANDIDATE_ROWS), static fn ($item): bool => $item instanceof KnowledgeClaim && $item->canonicalId !== $claim->canonicalId));
    }

    private function propositionKey(string $text): string { $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text); $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? ''; $tokens = array_values(array_filter(explode(' ', trim($text)), static fn (string $v): bool => $v !== '')); sort($tokens, SORT_STRING); return implode(' ', $tokens); }
    private function isProcessContamination(string $text, array $metadata): bool { return preg_match('/\b(resolve|parser|parse|normalize|standardize|hệ thống|parser nhận diện|được tạo|được sinh|chuẩn hóa|mô hình nhận diện|ai xác định)\b/ui', $text) === 1 || in_array(strtoupper((string) ($metadata['origin'] ?? '')), ['SYSTEM_WORKFLOW', 'AI_GENERATED', 'DERIVED_TRANSCRIPTION'], true); }
    private function isEditorialFragment(string $text, array $metadata): bool { return ($metadata['editorial_only'] ?? false) === true || in_array(strtoupper((string) ($metadata['source_kind'] ?? '')), ['ARTICLE', 'ARTICLE_SUMMARY', 'SEO', 'GENERATED_ARTICLE'], true); }
    private function needsAtomization(string $text): bool { return preg_match('/[,;].*\b(and|và|đồng thời|thường gặp|sản xuất|dùng|có)\b/ui', $text) === 1 || substr_count($text, ',') >= 2; }
    private function narrowestScope(string $scope, array $subject): string { return ($subject['entity_type'] ?? '') === 'specimen' && $scope !== 'specimen_observation' ? 'specimen_observation' : ($scope !== '' ? $scope : 'UNRESOLVED'); }
    /** @param list<string> $findings @return list<array<string,mixed>> */
    private function repairs(array $findings): array
    {
        $map = [
            'DUPLICATE_OR_REUSE' => 'REUSE_CANONICAL',
            'EVIDENCE_GAP' => 'ADD_EVIDENCE',
            'QUALIFICATION_REVIEW' => 'QUALIFY_CLAIM',
            'ATOMIZATION_NEEDED' => 'ATOMIZE',
            'SCOPE_PROBLEM' => 'SCOPE_NARROW_REVIEW',
            'PROCESS_CONTAMINATION' => 'RETIRE_PROCESS_CONTAMINATION_REVIEW',
            'EDITORIAL_FRAGMENT' => 'RETIRE_EDITORIAL_FRAGMENT_REVIEW',
            'INTERNAL_WORKFLOW_KNOWLEDGE' => 'RETIRE_INTERNAL_WORKFLOW_REVIEW',
            'DICTIONARY_CANDIDATE' => 'DICTIONARY_REVIEW',
            'RELATION_CANDIDATE' => 'RELATION_REVIEW',
        ];
        $contamination = ['PROCESS_CONTAMINATION', 'EDITORIAL_FRAGMENT', 'INTERNAL_WORKFLOW_KNOWLEDGE', 'DERIVED_CONTENT_CONTAMINATION'];
        if (array_intersect($contamination, $findings) !== []) {
            $findings = array_values(array_diff($findings, ['EVIDENCE_GAP', 'QUALIFICATION_REVIEW', 'DICTIONARY_CANDIDATE', 'RELATION_CANDIDATE']));
        }
        return array_values(array_map(
            static fn (string $finding): array => ['action' => $map[$finding] ?? 'NO_ACTION', 'finding' => $finding, 'planning_only' => true],
            array_filter($findings, static fn (string $finding): bool => isset($map[$finding])),
        ));
    }
}
