<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{KnowledgeClaim, KnowledgeEnrichmentCandidate, KnowledgeFacetProfile};

final class KnowledgeEnrichmentPlanner
{
    public function __construct(private KnowledgeRepository $claims, private EvidenceRepository $evidence, private SourceRepository $sources) {}

    /** @return list<KnowledgeEnrichmentCandidate> */
    public function plan(string $subjectId, KnowledgeFacetProfile $profile, string $observation, array $context = []): array
    {
        if (($context['unsupported'] ?? false) === true) return [new KnowledgeEnrichmentCandidate('unsupported', $subjectId, $profile, $observation, ['reason' => 'Input is outside the approved enrichment boundary'])];
        if (($context['ambiguous'] ?? false) === true) return [new KnowledgeEnrichmentCandidate('ambiguous', $subjectId, $profile, $observation, ['reason' => 'Subject or scope requires review'])];
        $relation = (string) ($context['relation'] ?? '');
        if (in_array($relation, ['supports', 'qualifies', 'contradicts'], true)) {
            $classification = $relation === 'supports' ? 'add_evidence' : ($relation === 'qualifies' ? 'qualify' : 'contradict');
            $claimId = (string) ($context['claim_id'] ?? '');
            $sourceId = (string) ($context['source_id'] ?? '');
            $claim = $this->claims->findByCanonicalId($claimId);
            $source = $this->sources->findByCanonicalId($sourceId);
            if ($claim === null || $source === null) return [new KnowledgeEnrichmentCandidate('ambiguous', $subjectId, $profile, $observation, ['candidate_kind' => 'evidence_review', 'reason' => 'Claim or source is unresolved', 'claim_id' => $claimId, 'relation' => $relation])];
            return [new KnowledgeEnrichmentCandidate($classification, $subjectId, $profile, $observation, ['claim_id' => $claim->canonicalId, 'source_id' => $source->canonicalId, 'claim_revision' => $claim->revision, 'source_revision' => $source->revision, 'relation' => $relation, 'locator' => $context['locator'] ?? null, 'metadata' => is_array($context['metadata'] ?? null) ? $context['metadata'] : []])];
        }
        $normalized = $this->normalize($observation);
        foreach ($this->claims->list() as $claim) {
            if (!$claim instanceof KnowledgeClaim || !$claim->active || !$this->sameContext($claim, $subjectId, $profile) || $this->normalize($claim->claimText) !== $normalized) continue;
            return [new KnowledgeEnrichmentCandidate('same_claim', $subjectId, $profile, $observation, ['matched_claim_id' => $claim->canonicalId])];
        }
        return [new KnowledgeEnrichmentCandidate('new_claim', $subjectId, $profile, $observation, ['reason' => 'No exact semantic match; requires governed review'])];
    }

    private function sameContext(KnowledgeClaim $claim, string $subjectId, KnowledgeFacetProfile $profile): bool
    {
        $metadata = $claim->provenance['metadata'] ?? [];
        return is_array($metadata) && ($metadata['subject_id'] ?? null) === $subjectId && ($metadata['facet'] ?? null) === $profile->facet && ($metadata['scope'] ?? null) === $profile->scope;
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
