<?php
declare(strict_types=1);

namespace NHK\Core\Application\Inventory;

use NHK\Core\Contracts\Graph\GraphRepository;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, GraphEdge, PredicateRegistry};

final class GraphInventoryService
{
    public function __construct(private GraphRepository $repository, private EndpointTypeRegistry $endpoints, private PredicateRegistry $predicates) {}

    public function inventory(array $filters, int $limit = 50, ?string $after = null): GraphInventoryReport
    {
        $limit = max(1, min(10000, $limit));
        $all = $this->repository->allEdges(true);
        $logicalCounts = [];
        foreach ($all as $edge) $logicalCounts[$this->logicalKey($edge)] = ($logicalCounts[$this->logicalKey($edge)] ?? 0) + 1;
        $rows = [];
        foreach ($all as $edge) {
            $row = $this->normalize($edge, ($logicalCounts[$this->logicalKey($edge)] ?? 1) > 1);
            if (!$this->matches($row, $filters)) continue;
            $rows[] = $row;
        }
        usort($rows, static fn (array $left, array $right): int => $left['edge_uuid'] <=> $right['edge_uuid']);
        if ($after !== null && $after !== '') $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['edge_uuid'] > $after));
        $total = count($rows);
        $items = array_slice($rows, 0, $limit);
        $next = count($rows) > $limit ? $items[count($items) - 1]['edge_uuid'] : null;
        $counters = ['total' => $total, 'active' => 0, 'retired' => 0, 'dangling' => 0, 'invalid_endpoint' => 0, 'duplicate' => 0];
        foreach ($rows as $row) {
            $row['state'] === 'ACTIVE' ? $counters['active']++ : $counters['retired']++;
            if (in_array('dangling', $row['diagnostics'], true)) $counters['dangling']++;
            if (in_array('invalid_endpoint', $row['diagnostics'], true)) $counters['invalid_endpoint']++;
            if (in_array('duplicate', $row['diagnostics'], true)) $counters['duplicate']++;
        }
        return new GraphInventoryReport($items, $total, $next, $counters);
    }

    /** @return array<string,mixed> */
    private function normalize(GraphEdge $edge, bool $duplicate): array
    {
        $diagnostics = [];
        foreach ([$edge->source, $edge->target] as $node) {
            try {
                $resolver = $this->endpoints->resolver($node->reference->endpoint_type);
                if (!$resolver->exists($node->reference)) $diagnostics[] = 'dangling';
            } catch (\Throwable) {
                $diagnostics[] = 'invalid_endpoint';
            }
        }
        try { $this->predicates->get($edge->predicate); } catch (\Throwable) { $diagnostics[] = 'invalid_predicate'; }
        if ($duplicate) $diagnostics[] = 'duplicate';
        $diagnostics = array_values(array_unique($diagnostics));
        return [
            'edge_uuid' => $edge->edge_uuid,
            'source' => ['type' => $edge->source->reference->endpoint_type, 'uuid' => $edge->source->reference->endpoint_key],
            'predicate' => $edge->predicate,
            'target' => ['type' => $edge->target->reference->endpoint_type, 'uuid' => $edge->target->reference->endpoint_key],
            'direction' => 'outbound',
            'inverse_direction' => 'inbound',
            'state' => $edge->isActive() ? 'ACTIVE' : 'RETIRED',
            'active' => $edge->isActive(),
            'revision' => $edge->revision,
            'diagnostics' => $diagnostics,
        ];
    }

    private function matches(array $row, array $filters): bool
    {
        foreach (['predicate', 'state'] as $key) if (isset($filters[$key]) && (string) $filters[$key] !== (string) $row[$key]) return false;
        foreach (['source_type' => 'source', 'target_type' => 'target'] as $filter => $side) if (isset($filters[$filter]) && (string) $filters[$filter] !== (string) $row[$side]['type']) return false;
        if (isset($filters['direction']) && !in_array($filters['direction'], ['both', 'outbound', 'inbound'], true)) return false;
        return true;
    }

    private function logicalKey(GraphEdge $edge): string
    {
        return $edge->source->reference->key() . '|' . $edge->predicate . '|' . $edge->target->reference->key();
    }
}
