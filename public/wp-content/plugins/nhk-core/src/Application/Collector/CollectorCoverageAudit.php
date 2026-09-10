<?php
declare(strict_types=1);

namespace NHK\Core\Application\Collector;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Knowledge\CollectorFacetRegistry;

/** Read-only coverage report for Collector Profile branches. */
final class CollectorCoverageAudit
{
    public function __construct(private AuthorityRepository $authority, private CollectorProfileQuery $profiles) {}

    /** @return array{summary:array<string,int>,items:list<array<string,mixed>>} */
    public function run(): array
    {
        $items = [];
        $summary = ['classification_count' => 0, 'available_count' => 0, 'complete_count' => 0, 'truncated_count' => 0, 'unresolved_count' => 0, 'knowledge_count' => 0, 'media_count' => 0, 'video_count' => 0];
        foreach ($this->authority->listByType('classification') as $entity) {
            if (!$entity instanceof AuthorityEntity || !$entity->active()) continue;
            $summary['classification_count']++;
            $profile = $this->profiles->build($entity->canonicalId, 1, 200);
            $coverage = is_array($profile['coverage'] ?? null) ? $profile['coverage'] : [];
            $unresolved = count(is_array($profile['unresolved'] ?? null) ? $profile['unresolved'] : []);
            $knowledge = (int) ($coverage['knowledge_count'] ?? 0);
            $media = (int) ($coverage['media_count'] ?? 0);
            $videos = (int) ($coverage['video_count'] ?? 0);
            $available = ($profile['status'] ?? '') === 'available';
            $complete = $available && ($coverage['complete'] ?? false) === true;
            if ($available) $summary['available_count']++;
            if ($complete) $summary['complete_count']++;
            if (($coverage['truncated'] ?? false) === true) $summary['truncated_count']++;
            $summary['unresolved_count'] += $unresolved;
            $summary['knowledge_count'] += $knowledge;
            $summary['media_count'] += $media;
            $summary['video_count'] += $videos;
            $facetMatrix = [];
            foreach (CollectorFacetRegistry::all() as $facet) {
                $claims = is_array($profile['facets'][$facet] ?? null) ? $profile['facets'][$facet] : [];
                $statuses = array_map(static fn (mixed $claim): string => is_array($claim) ? (string) ($claim['status'] ?? 'unresolved') : 'unresolved', $claims);
                $facetMatrix[$facet] = ['count' => count($claims), 'status' => in_array('verified', $statuses, true) ? 'VERIFIED' : (in_array('partial', $statuses, true) ? 'PARTIAL' : 'UNRESOLVED')];
            }
            $items[] = [
                'stable_key' => $entity->stableKey,
                'name' => $entity->canonicalName,
                'status' => $available ? ($complete ? 'COMPLETE' : 'PARTIAL') : 'UNAVAILABLE',
                'reason' => (string) ($profile['reason'] ?? ''),
                'knowledge_count' => $knowledge,
                'unresolved_count' => $unresolved,
                'media_count' => $media,
                'video_count' => $videos,
                'facet_counts' => array_map('count', is_array($profile['facets'] ?? null) ? $profile['facets'] : []),
                'facet_matrix' => $facetMatrix,
            ];
        }
        usort($items, static fn (array $left, array $right): int => [(string) $left['stable_key']] <=> [(string) $right['stable_key']]);
        return ['summary' => $summary, 'items' => $items];
    }
}
