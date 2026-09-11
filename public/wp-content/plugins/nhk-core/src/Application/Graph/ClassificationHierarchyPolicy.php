<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Graph\GraphRepository;
use NHK\Core\Domain\Graph\NodeReference;

/** Validates the Graph-owned classification hierarchy before edge creation. */
final class ClassificationHierarchyPolicy
{
    public function __construct(private AuthorityRepository $authority, private GraphRepository $graph, private int $maxVisited = 200) {}

    public function assertCanCreate(NodeReference $source, NodeReference $target): void
    {
        if ($source->endpoint_type !== 'classification' || $target->endpoint_type !== 'classification') throw new \RuntimeException('CLASSIFICATION_HIERARCHY_ENDPOINT_INVALID');
        if ($source->endpoint_key === $target->endpoint_key) throw new \RuntimeException('CLASSIFICATION_HIERARCHY_SELF_RELATION');
        $sourceEntity = $this->authority->findByCanonicalId($source->endpoint_key);
        $targetEntity = $this->authority->findByCanonicalId($target->endpoint_key);
        if ($sourceEntity === null || $targetEntity === null || !$sourceEntity->active() || !$targetEntity->active()) throw new \RuntimeException('CLASSIFICATION_ENDPOINT_INACTIVE');
        $sourceFamily = trim((string) ($sourceEntity->payload['family'] ?? ''));
        $targetFamily = trim((string) ($targetEntity->payload['family'] ?? ''));
        if ($sourceFamily === '' || $targetFamily === '') throw new \RuntimeException('CLASSIFICATION_FAMILY_UNRESOLVED');
        if ($sourceFamily !== $targetFamily) throw new \RuntimeException('CLASSIFICATION_FAMILY_INCOMPATIBLE');
        if ($this->reaches($target->endpoint_key, $source->endpoint_key)) throw new \RuntimeException('CLASSIFICATION_HIERARCHY_CYCLE');
    }

    private function reaches(string $start, string $needle): bool
    {
        $queue = [$start]; $visited = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_string($current) || isset($visited[$current])) continue;
            $visited[$current] = true;
            if (count($visited) > $this->maxVisited) throw new \RuntimeException('CLASSIFICATION_HIERARCHY_BOUND_EXCEEDED');
            $node = $this->graph->findNode(new NodeReference('classification', $current));
            if ($node === null) continue;
            foreach ((array) $this->graph->outgoing($node, 'subtype_of', 0, 200, false, 'classification')['items'] as $edge) {
                $next = $edge->target->reference->endpoint_key;
                if ($next === $needle) return true;
                $queue[] = $next;
            }
        }
        return false;
    }
}
