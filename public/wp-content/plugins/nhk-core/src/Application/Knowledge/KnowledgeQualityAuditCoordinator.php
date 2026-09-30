<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Contracts\Knowledge\KnowledgePageReader;
use NHK\Core\Domain\Knowledge\KnowledgeClaim;

/** Bounded read-only collection audit with deterministic stable-key pagination. */
final class KnowledgeQualityAuditCoordinator
{
    public function __construct(private KnowledgeQualityAuditor $auditor, private KnowledgeRepository $claims) {}

    /** @return array<string,mixed> */
    public function auditBatch(int $limit = 50, ?string $cursor = null, bool $includeRetired = true, array $filters = []): array
    {
        $limit = max(1, min(200, $limit));
        $hasMore = false;
        $results = [];
        $next = $cursor;
        if ($this->claims instanceof KnowledgePageReader) {
            do {
                $read = $this->claims->page($includeRetired, $next, $limit);
                $page = array_values(array_filter($read['items'], static fn ($claim): bool => $claim instanceof KnowledgeClaim));
                $hasMore = (bool) $read['has_more'];
                foreach ($page as $claim) {
                    $next = $claim->stableKey;
                    $result = $this->auditor->audit($claim)->toArray(false);
                    $result['stable_key'] = $claim->stableKey;
                    if ($this->matchesFilters($result, $filters)) $results[] = $result;
                    if (count($results) >= $limit) break;
                }
                if ($page === []) $hasMore = false;
            } while ($hasMore && count($results) < $limit);
        } else {
            $items = array_values(array_filter($this->claims->list($includeRetired), static fn ($claim): bool => $claim instanceof KnowledgeClaim && ($cursor === null || $claim->stableKey > $cursor)));
            usort($items, static fn (KnowledgeClaim $a, KnowledgeClaim $b): int => strcmp($a->stableKey, $b->stableKey));
            foreach ($items as $claim) {
                $next = $claim->stableKey;
                $result = $this->auditor->audit($claim)->toArray(false);
                $result['stable_key'] = $claim->stableKey;
                if ($this->matchesFilters($result, $filters)) $results[] = $result;
                if (count($results) >= $limit) break;
            }
            $hasMore = count($items) > 0 && $next !== null && count(array_filter($items, static fn (KnowledgeClaim $claim): bool => $claim->stableKey > $next)) > 0;
        }
        $coverage = [];
        foreach ($results as $result) {
            $subject = (string) ($result['subject_resolution']['canonical_subject_id'] ?? '');
            $facet = (string) ($result['facet_classification']['facet'] ?? '');
            if ($subject === '' || $facet === '') continue;
            $coverage[$subject][$facet] = ($coverage[$subject][$facet] ?? 0) + (($result['writer_readiness']['eligible_claim'] ?? false) ? 1 : 0);
        }
        return ['status' => 'READ_ONLY_AUDIT', 'results' => $results, 'count' => count($results), 'next_cursor' => $hasMore ? $next : null, 'has_more' => $hasMore, 'mutated' => false, 'facet_coverage' => $coverage, 'diagnostics' => ['bounded_limit' => $limit, 'ordering' => 'stable_key_ascending', 'cursor' => $cursor, 'repository_reader' => $this->claims instanceof KnowledgePageReader ? 'paged' : 'legacy_list_fallback', 'filters' => $filters]];
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $filters */
    private function matchesFilters(array $result, array $filters): bool
    {
        $findings = array_map('strval', (array) ($result['quality_findings'] ?? []));
        $requestedFindings = array_map('strval', (array) ($filters['finding_filters'] ?? []));
        if ($requestedFindings !== [] && array_intersect($requestedFindings, $findings) === []) return false;
        if (isset($filters['subject_id']) && $filters['subject_id'] !== '' && (string) ($result['subject_resolution']['canonical_subject_id'] ?? '') !== $filters['subject_id']) return false;
        if (isset($filters['scope']) && $filters['scope'] !== '' && (string) ($result['scope_assessment']['scope'] ?? '') !== $filters['scope']) return false;
        if (isset($filters['readiness']) && $filters['readiness'] !== '' && (string) ($result['writer_readiness']['status'] ?? '') !== $filters['readiness']) return false;
        return true;
    }
}
