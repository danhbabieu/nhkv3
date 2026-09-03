<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Graph;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Authority\SemanticMergeReferenceAdapter;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\NodeReference;

/** Moves Graph edges through GraphService; it never rewrites edge endpoints directly. */
final class SemanticMergeGraphAdapter implements SemanticMergeReferenceAdapter
{
    public function __construct(private GraphService $graph) {}

    public function enumerate(AuthorityEntity $source, AuthorityEntity $target): array
    {
        $ref = new NodeReference($source->entityType, $source->stableKey);
        return array_merge(array_map(fn($edge) => $this->row($edge, 'outbound'), $this->all($ref, true)), array_map(fn($edge) => $this->row($edge, 'inbound'), $this->all($ref, false)));
    }

    public function plan(AuthorityEntity $source, AuthorityEntity $target, array $references): array
    {
        $planned = [];
        foreach ($references as $reference) {
            if (($reference['surface'] ?? '') !== 'graph') continue;
            $from = new NodeReference((string) $reference['source_type'], (string) $reference['source_key']);
            $to = new NodeReference((string) $reference['target_type'], (string) $reference['target_key']);
            $newSource = $reference['direction'] === 'outbound' ? new NodeReference($target->entityType, $target->stableKey) : $from;
            $newTarget = $reference['direction'] === 'outbound' ? $to : new NodeReference($target->entityType, $target->stableKey);
            if ($newSource->key() === $newTarget->key()) throw new \RuntimeException('Merge would create a forbidden self relation.');
            $planned[] = ['surface'=>'graph','edge_uuid'=>(string) $reference['edge_uuid'],'edge_revision'=>(int) $reference['edge_revision'],'source_type'=>$newSource->endpoint_type,'source_key'=>$newSource->endpoint_key,'predicate'=>(string) $reference['predicate'],'target_type'=>$newTarget->endpoint_type,'target_key'=>$newTarget->endpoint_key,'old_source_type'=>$from->endpoint_type,'old_source_key'=>$from->endpoint_key,'old_target_type'=>$to->endpoint_type,'old_target_key'=>$to->endpoint_key];
        }
        return $planned;
    }

    public function apply(array $planned): array
    {
        $source = new NodeReference((string) $planned['source_type'], (string) $planned['source_key']);
        $target = new NodeReference((string) $planned['target_type'], (string) $planned['target_key']);
        $existing = $this->graph->findEdge($source, (string) $planned['predicate'], $target);
        if ($existing === null) $this->graph->create($source, (string) $planned['predicate'], $target);
        $this->graph->retire((string) $planned['edge_uuid'], (int) $planned['edge_revision']);
        return ['action' => $existing === null ? 'moved' : 'deduped', 'reference' => (string) $planned['edge_uuid']];
    }

    public function verify(array $planned): bool
    {
        $old = new NodeReference((string) $planned['old_source_type'], (string) $planned['old_source_key']);
        $new = new NodeReference((string) $planned['source_type'], (string) $planned['source_key']);
        $target = new NodeReference((string) $planned['target_type'], (string) $planned['target_key']);
        return $this->graph->findEdge($old, (string) $planned['predicate'], new NodeReference((string) $planned['old_target_type'], (string) $planned['old_target_key'])) === null
            && ($new->key() === $target->key() || $this->graph->findEdge($new, (string) $planned['predicate'], $target) !== null);
    }

    private function all(NodeReference $reference, bool $out): array
    {
        $items = []; $cursor = 0;
        do { $page = $out ? $this->graph->findOutgoing($reference, null, $cursor, 200) : $this->graph->findIncoming($reference, null, $cursor, 200); $items = array_merge($items, $page['items']); $cursor = $page['next_cursor'] ?? 0; } while ($cursor > 0);
        return $items;
    }

    private function row(object $edge, string $direction): array
    {
        return ['surface'=>'graph','direction'=>$direction,'edge_uuid'=>$edge->edge_uuid,'edge_revision'=>$edge->revision,'source_type'=>$edge->source->reference->endpoint_type,'source_key'=>$edge->source->reference->endpoint_key,'predicate'=>$edge->predicate,'target_type'=>$edge->target->reference->endpoint_type,'target_key'=>$edge->target->reference->endpoint_key];
    }
}
