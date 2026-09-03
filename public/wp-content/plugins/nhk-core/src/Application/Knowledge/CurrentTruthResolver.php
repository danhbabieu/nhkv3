<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, KnowledgeFacetProfile};

final class CurrentTruthResolver
{
    public function __construct(private KnowledgeRepository $claims, private EvidenceRepository $evidence, private SourceRepository $sources) {}

    public function resolve(string $subjectId, KnowledgeFacetProfile $profile): CurrentTruthPacket
    {
        $claims = [];
        $qualifiers = [];
        $contradictions = [];
        foreach ($this->claims->list() as $claim) {
            if (!$claim instanceof KnowledgeClaim || !$claim->active || !$claim->isPublic() || !$this->context($claim, $subjectId, $profile)) continue;
            $claims[] = $claim;
            foreach ($this->evidence->listByClaim($claim->canonicalId) as $evidence) {
                if (!$evidence instanceof Evidence || !$evidence->active || !$evidence->isPublic()) continue;
                $source = $this->sources->findByCanonicalId($evidence->sourceId);
                if ($source === null || !$source->active || !$source->isPublic()) continue;
                if ($evidence->relation === 'qualifies') $qualifiers[] = $evidence;
                if ($evidence->relation === 'contradicts') $contradictions[] = $evidence;
            }
        }
        return new CurrentTruthPacket($subjectId, $profile, $claims, $qualifiers, $contradictions, ['claim_count' => count($claims), 'qualifier_count' => count($qualifiers), 'contradiction_count' => count($contradictions)]);
    }

    private function context(KnowledgeClaim $claim, string $subjectId, KnowledgeFacetProfile $profile): bool
    {
        $metadata = $claim->provenance['metadata'] ?? [];
        return is_array($metadata) && ($metadata['subject_id'] ?? null) === $subjectId && ($metadata['facet'] ?? null) === $profile->facet && ($metadata['scope'] ?? null) === $profile->scope;
    }
}
