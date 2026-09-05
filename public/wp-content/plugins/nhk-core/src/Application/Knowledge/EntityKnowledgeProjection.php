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

    /** @return array<string,mixed> */
    public function forSubject(string $subjectId): array
    {
        $emptyCoverage = [
            'sourced_claim_count' => 0,
            'unsourced_claim_count' => 0,
            'qualification_count' => 0,
            'contradiction_count' => 0,
            'specimen_observation_count' => 0,
        ];
        if ($this->status !== null && !$this->status->knowledgeStorageReady()) {
            return ['status' => 'UNAVAILABLE', 'facets' => [], 'claim_count' => 0, 'evidence_count' => 0, 'coverage' => $emptyCoverage, 'warnings' => ['KNOWLEDGE_UNAVAILABLE']];
        }

        $facets = [];
        $claimCount = 0;
        $evidenceCount = 0;
        $coverage = $emptyCoverage;
        foreach ($this->claims->list() as $claim) {
            if (!$claim instanceof KnowledgeClaim || !$claim->active || !$claim->isPublic()) continue;
            $metadata = $claim->provenance['metadata'] ?? null;
            if (!is_array($metadata) || (string) ($metadata['subject_id'] ?? '') !== $subjectId) continue;
            $facet = (string) ($metadata['facet'] ?? '');
            $scope = (string) ($metadata['scope'] ?? '');
            if (!in_array($facet, KnowledgeFacetProfile::FACETS, true) || !in_array($scope, KnowledgeFacetProfile::SCOPES, true)) continue;

            $citations = $this->publicEvidence($claim);
            $evidenceCount += count($citations);
            $relations = array_values(array_unique(array_map(static fn(array $item): string => (string) ($item['relation'] ?? ''), $citations)));
            if ($citations === []) $coverage['unsourced_claim_count']++;
            else $coverage['sourced_claim_count']++;
            $coverage['qualification_count'] += count(array_filter($citations, static fn(array $item): bool => ($item['relation'] ?? '') === 'qualifies'));
            $coverage['contradiction_count'] += count(array_filter($citations, static fn(array $item): bool => ($item['relation'] ?? '') === 'contradicts'));
            if ($facet === 'specimen_observation' || $scope === 'specimen_observation') $coverage['specimen_observation_count']++;

            $facets[$facet][] = [
                'text' => $claim->claimText,
                'type' => $claim->claimType,
                'facet' => $facet,
                'scope' => $scope,
                'evidence' => $citations,
                'has_evidence' => $citations !== [],
                'evidence_state' => $citations === [] ? 'PUBLIC_UNSOURCED' : 'SOURCED',
                'evidence_relations' => $relations,
            ];
            $claimCount++;
        }

        foreach ($facets as &$items) usort($items, static fn(array $a, array $b): int => strcmp((string) $a['text'], (string) $b['text']));
        unset($items);
        $warnings = [];
        if ($coverage['unsourced_claim_count'] > 0) $warnings[] = 'PUBLIC_CLAIMS_WITHOUT_EVIDENCE';
        if ($coverage['contradiction_count'] > 0) $warnings[] = 'PUBLIC_CONTRADICTION_PRESENT';
        if ($claimCount > 0 && $coverage['specimen_observation_count'] === $claimCount) $warnings[] = 'SPECIMEN_OBSERVATION_SCOPE_ONLY';

        return [
            'status' => 'AVAILABLE',
            'facets' => $facets,
            'claim_count' => $claimCount,
            'evidence_count' => $evidenceCount,
            'coverage' => $coverage,
            'warnings' => $warnings,
        ];
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
