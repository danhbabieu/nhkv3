<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\{GraphEdge, NodeReference};

final class StructuralContextQuery
{
    public function __construct(private GraphService $graph, private AuthorityRepository $authority) {}

    public function forModel(string $modelId): StructuralContext
    {
        $model = $this->authority->findByCanonicalId($modelId);
        if (!$this->active($model, 'model')) return new StructuralContext('model', $modelId, $modelId, null, [], ['STRUCTURAL_PARENT_MISSING']);
        $edges = $this->outgoing('model', $modelId, 'model_of');
        if (count($edges) !== 1) return new StructuralContext('model', $modelId, $modelId, null, [], [count($edges) === 0 ? 'STRUCTURAL_PARENT_MISSING' : 'STRUCTURAL_PARENT_AMBIGUOUS']);
        $brandId = $edges[0]->target->reference->endpoint_key;
        $brand = $this->authority->findByCanonicalId($brandId);
        if (!$this->active($brand, 'brand')) return new StructuralContext('model', $modelId, $modelId, null, [], ['STRUCTURAL_PARENT_MISSING']);
        return new StructuralContext('model', $modelId, $modelId, $brandId, ['model_of']);
    }

    public function forVariant(string $variantId): StructuralContext
    {
        $variant = $this->authority->findByCanonicalId($variantId);
        if (!$this->active($variant, 'variant')) return new StructuralContext('variant', $variantId, null, null, [], ['STRUCTURAL_PARENT_MISSING']);
        $edges = $this->outgoing('variant', $variantId, 'variant_of');
        if (count($edges) !== 1) return new StructuralContext('variant', $variantId, null, null, [], [count($edges) === 0 ? 'STRUCTURAL_PARENT_MISSING' : 'STRUCTURAL_PARENT_AMBIGUOUS']);
        $modelId = $edges[0]->target->reference->endpoint_key;
        $model = $this->authority->findByCanonicalId($modelId);
        if (!$this->active($model, 'model')) return new StructuralContext('variant', $variantId, $modelId, null, ['variant_of'], ['STRUCTURAL_PARENT_MISSING']);
        $modelEdges = $this->outgoing('model', $modelId, 'model_of');
        if (count($modelEdges) !== 1) return new StructuralContext('variant', $variantId, $modelId, null, ['variant_of'], [count($modelEdges) === 0 ? 'STRUCTURAL_PARENT_MISSING' : 'STRUCTURAL_PARENT_AMBIGUOUS']);
        $brandId = $modelEdges[0]->target->reference->endpoint_key;
        if (!$this->active($this->authority->findByCanonicalId($brandId), 'brand')) return new StructuralContext('variant', $variantId, $modelId, null, ['variant_of', 'model_of'], ['STRUCTURAL_PARENT_MISSING']);
        return new StructuralContext('variant', $variantId, $modelId, $brandId, ['variant_of', 'model_of']);
    }

    /** @return list<GraphEdge> */
    private function outgoing(string $type, string $id, string $predicate): array
    {
        try { return array_values(array_filter($this->graph->findOutgoing(new NodeReference($type, $id), $predicate, 0, 200)['items'], static fn (GraphEdge $edge): bool => $edge->isActive())); }
        catch (\Throwable) { return []; }
    }

    private function active(?AuthorityEntity $entity, string $type): bool { return $entity !== null && $entity->entityType === $type && $entity->active(); }
}
