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
    public function auditBatch(int $limit = 50, ?string $cursor = null, bool $includeRetired = true): array
    {
        $limit = max(1, min(200, $limit));
        $hasMore = false;
        if ($this->claims instanceof KnowledgePageReader) {
            $read = $this->claims->page($includeRetired, $cursor, $limit);
            $page = array_values(array_filter($read['items'], static fn ($claim): bool => $claim instanceof KnowledgeClaim));
            $hasMore = $read['has_more'];
        } else {
            $items = array_values(array_filter($this->claims->list($includeRetired), static fn ($claim): bool => $claim instanceof KnowledgeClaim && ($cursor === null || $claim->stableKey > $cursor)));
            usort($items, static fn (KnowledgeClaim $a, KnowledgeClaim $b): int => strcmp($a->stableKey, $b->stableKey));
            $page = array_slice($items, 0, $limit);
            $hasMore = count($items) > $limit;
        }
        $results = array_map(fn (KnowledgeClaim $claim): array => $this->auditor->audit($claim)->toArray(true), $page);
        $next = $page !== [] ? $page[count($page) - 1]->stableKey : null;
        $coverage = [];
        foreach ($results as $result) {
            $subject = (string) ($result['subject_resolution']['canonical_subject_id'] ?? '');
            $facet = (string) ($result['facet_classification']['facet'] ?? '');
            if ($subject === '' || $facet === '') continue;
            $coverage[$subject][$facet] = ($coverage[$subject][$facet] ?? 0) + (($result['writer_readiness']['eligible_claim'] ?? false) ? 1 : 0);
        }
        return ['status' => 'READ_ONLY_AUDIT', 'results' => $results, 'count' => count($results), 'next_cursor' => $hasMore ? $next : null, 'has_more' => $hasMore, 'mutated' => false, 'facet_coverage' => $coverage, 'diagnostics' => ['bounded_limit' => $limit, 'ordering' => 'stable_key_ascending', 'cursor' => $cursor, 'repository_reader' => $this->claims instanceof KnowledgePageReader ? 'paged' : 'legacy_list_fallback']];
    }
}
