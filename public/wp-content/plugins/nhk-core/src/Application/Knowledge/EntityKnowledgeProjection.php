<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, KnowledgeFacetProfile, Source};
use NHK\Core\Shared\Migration\MigrationStatus;

/** Read-only, public-only Knowledge projection for one canonical subject. */
final class EntityKnowledgeProjection
{
    public function __construct(
        private KnowledgeRepository $claims,
        private EvidenceRepository $evidence,
        private SourceRepository $sources,
        private ?MigrationStatus $status = null,
    ) {}

    /** @return array{status:string,facets:array<string,list<array<string,mixed>>>,claim_count:int,evidence_count:int} */
    public function forSubject(string $subjectId): array
    {
        if ($this->status !== null && !$this->status->knowledgeStorageReady()) {
            return ['status' => 'UNAVAILABLE', 'facets' => [], 'claim_count' => 0, 'evidence_count' => 0];
        }
        $facets = [];
        $claimCount = 0;
        $evidenceCount = 0;
        foreach ($this->claims->list() as $claim) {
            if (!$claim instanceof KnowledgeClaim || !$claim->active || !$claim->isPublic()) continue;
            $metadata = $claim->provenance['metadata'] ?? null;
            if (!is_array($metadata) || (string) ($metadata['subject_id'] ?? '') !== $subjectId) continue;
            $facet = (string) ($metadata['facet'] ?? '');
            $scope = (string) ($metadata['scope'] ?? '');
            if (!in_array($facet, KnowledgeFacetProfile::FACETS, true) || !in_array($scope, KnowledgeFacetProfile::SCOPES, true)) continue;
            $citations = $this->publicEvidence($claim);
            $evidenceCount += count($citations);
            $facets[$facet][] = [
                'text' => $claim->claimText,
                'type' => $claim->claimType,
                'evidence' => $citations,
                'has_evidence' => $citations !== [],
            ];
            $claimCount++;
        }
        foreach ($facets as &$items) usort($items, static fn(array $a, array $b): int => strcmp((string) $a['text'], (string) $b['text']));
        unset($items);
        return ['status' => 'AVAILABLE', 'facets' => $facets, 'claim_count' => $claimCount, 'evidence_count' => $evidenceCount];
    }

    /** @return list<array<string,mixed>> */
    private function publicEvidence(KnowledgeClaim $claim): array
    {
        $items = [];
        foreach ($this->evidence->listByClaim($claim->canonicalId) as $evidence) {
            if (!$evidence instanceof Evidence || !$evidence->active || !$evidence->isPublic()) continue;
            $source = $this->sources->findByCanonicalId($evidence->sourceId);
            if (!$source instanceof Source || !$source->active || !$source->isPublic()) continue;
            $items[] = [
                'relation' => $evidence->relation,
                'excerpt' => $evidence->excerpt,
                'locator' => $evidence->locator,
                'source_title' => $source->title,
                'source_type' => $source->sourceType,
                'source_locator' => $source->locator,
            ];
        }
        return $items;
    }
}
