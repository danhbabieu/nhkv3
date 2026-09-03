<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Domain\Graph\{NodeReference, GraphEdge};

/** Bounded, registry-driven semantic traversal. It only reads through GraphService. */
final class RelatedSemanticQuery
{
    public const MAX_HOPS = 2;

    public function __construct(private GraphService $graph, private PredicateTraversalPolicy $policy) {}

    /** @return array{status:string,items:list<array<string,mixed>>,reason?:string} */
    public function query(NodeReference $source, array $targetTypes = [], int $maxHops = 2, int $limit = 50): array
    {
        if ($maxHops < 1 || $maxHops > self::MAX_HOPS || $limit < 1) return ['status' => 'unsupported', 'items' => [], 'reason' => 'BOUNDS_INVALID'];
        $limit = min($limit, 200);
        $seen = [$source->key() => true];
        $best = [];
        $queue = [[$source, 0, []]];
        try {
            while ($queue !== []) {
                [$current, $depth, $path] = array_shift($queue);
                if ($depth >= $maxHops) continue;
                foreach (['outgoing', 'incoming'] as $direction) {
                    $page = $direction === 'outgoing'
                        ? $this->graph->findOutgoing($current, null, 0, min(50, $limit))
                        : $this->graph->findIncoming($current, null, 0, min(50, $limit));
                    foreach ($page['items'] as $edge) {
                        if (!$edge instanceof GraphEdge || !$edge->isActive()) continue;
                        $other = $direction === 'outgoing' ? $edge->target->reference : $edge->source->reference;
                        if (!$this->policy->permits($current, $direction, $other, $edge->predicate)) continue;
                        $nextPath = array_merge($path, [['source' => $current->key(), 'predicate' => $edge->predicate, 'target' => $other->key()]]);
                        $hops = $depth + 1;
                        if ($other->key() !== $source->key() && ($targetTypes === [] || in_array($other->endpoint_type, $targetTypes, true))) {
                            $key = $other->key();
                            $candidate = ['target_entity_id' => $other->endpoint_key, 'target_entity_type' => $other->endpoint_type, 'relationship_class' => $hops === 1 ? 'DIRECT' : 'DERIVED', 'hop_count' => $hops, 'best_path' => $nextPath, 'alternative_paths' => []];
                            if (!isset($best[$key]) || ($candidate['relationship_class'] === 'DIRECT' && $best[$key]['relationship_class'] === 'DERIVED')) {
                                if (isset($best[$key])) $candidate['alternative_paths'][] = $best[$key]['best_path'];
                                $best[$key] = $candidate;
                            } elseif ($best[$key]['best_path'] !== $nextPath) {
                                $best[$key]['alternative_paths'][] = $nextPath;
                            }
                        }
                        if (!isset($seen[$other->key()])) { $seen[$other->key()] = true; $queue[] = [$other, $hops, $nextPath]; }
                    }
                }
            }
        } catch (\Throwable $e) { return ['status' => 'unavailable', 'items' => [], 'reason' => 'GRAPH_RESEARCH_UNAVAILABLE']; }
        return ['status' => 'available', 'items' => array_values(array_slice($best, 0, $limit))];
    }
}
