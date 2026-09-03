<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{KnowledgeClaim, KnowledgeEnrichmentCandidate, KnowledgeFacetProfile};

final class KnowledgeEnrichmentPlanner
{
    public function __construct(private KnowledgeRepository $claims, private EvidenceRepository $evidence, private SourceRepository $sources) {}

    /** @return list<KnowledgeEnrichmentCandidate> */
    public function plan(string $subjectId, KnowledgeFacetProfile $profile, string $observation): array
    {
        $normalized = $this->normalize($observation);
        foreach ($this->claims->list(true) as $claim) {
            if (!$claim instanceof KnowledgeClaim || !$this->sameContext($claim, $subjectId, $profile) || $this->normalize($claim->claimText) !== $normalized) continue;
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
