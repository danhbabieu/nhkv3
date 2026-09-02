<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\{GraphEdge, NodeReference};

final class StructuralContextQuery
{
    public function __construct(private GraphService $graph, private AuthorityRepository $authority, private ?StructuralParentCompatibilityResolver $compatibility = null) { $this->compatibility ??= new StructuralParentCompatibilityResolver($authority); }

    public function forModel(string $modelId): StructuralContext
    {
        $model = $this->authority->findByCanonicalId($modelId);
        if (!$this->active($model, 'model')) return new StructuralContext('model', $modelId, $modelId, null, [], ['STRUCTURAL_PARENT_MISSING'], 'GRAPH', false);
        $edges = $this->outgoing('model', $modelId, 'model_of');
        if (count($edges) > 1) return new StructuralContext('model', $modelId, $modelId, null, [], ['STRUCTURAL_PARENT_AMBIGUOUS'], 'GRAPH', false);
        if ($edges === []) return $this->compatibilityContext($model);
        $brandId = $edges[0]->target->reference->endpoint_key;
        $brand = $this->authority->findByCanonicalId($brandId);
        if (!$this->active($brand, 'brand')) return new StructuralContext('model', $modelId, $modelId, null, [], ['STRUCTURAL_PARENT_MISSING'], 'GRAPH', false);
        $compatibility = $this->compatibility->resolve($model);
        if ($compatibility->parentId !== null && $compatibility->parentId !== $brandId) return new StructuralContext('model', $modelId, $modelId, null, ['model_of'], ['RELATIONSHIP_CONFLICT'], 'GRAPH', false);
        return new StructuralContext('model', $modelId, $modelId, $brandId, ['model_of']);
    }

    public function forVariant(string $variantId): StructuralContext
    {
        $variant = $this->authority->findByCanonicalId($variantId);
        if (!$this->active($variant, 'variant')) return new StructuralContext('variant', $variantId, null, null, [], ['STRUCTURAL_PARENT_MISSING'], 'GRAPH', false);
        $edges = $this->outgoing('variant', $variantId, 'variant_of');
        if (count($edges) > 1) return new StructuralContext('variant', $variantId, null, null, [], ['STRUCTURAL_PARENT_AMBIGUOUS'], 'GRAPH', false);
        if ($edges === []) return $this->compatibilityVariantContext($variant);
        $modelId = $edges[0]->target->reference->endpoint_key;
        $model = $this->authority->findByCanonicalId($modelId);
        if (!$this->active($model, 'model')) return new StructuralContext('variant', $variantId, $modelId, null, ['variant_of'], ['PARENT_ENTITY_MISSING'], 'GRAPH', false);
        $modelContext = $this->forModel($modelId);
        if ($modelContext->reasons !== []) return new StructuralContext('variant', $variantId, $modelId, null, ['variant_of'], $modelContext->reasons, $modelContext->source, false, $modelContext->warnings);
        $compatibility = $this->compatibility->resolve($variant);
        if ($compatibility->parentId !== null && $compatibility->parentId !== $modelId) return new StructuralContext('variant', $variantId, null, null, ['variant_of'], ['RELATIONSHIP_CONFLICT'], 'GRAPH', false);
        $brandId = $modelContext->brandId;
        return new StructuralContext('variant', $variantId, $modelId, $brandId, ['variant_of', 'model_of']);
    }

    private function compatibilityContext(\NHK\Core\Domain\Authority\AuthorityEntity $entity): StructuralContext
    {
        $candidate = $this->compatibility->resolve($entity);
        if (!$candidate->safe()) return new StructuralContext($entity->entityType, $entity->canonicalId, $entity->canonicalId, null, [], $this->compatibilityReasons($candidate), $candidate->source, false, $candidate->warnings);
        return new StructuralContext('model', $entity->canonicalId, $entity->canonicalId, $candidate->parentId, [], [], $candidate->source, false, $candidate->warnings);
    }

    private function compatibilityVariantContext(\NHK\Core\Domain\Authority\AuthorityEntity $entity): StructuralContext
    {
        $candidate = $this->compatibility->resolve($entity);
        if (!$candidate->safe()) return new StructuralContext('variant', $entity->canonicalId, null, null, [], $this->compatibilityReasons($candidate), $candidate->source, false, $candidate->warnings);
        $modelContext = $this->forModel($candidate->parentId);
        if ($modelContext->reasons !== []) return new StructuralContext('variant', $entity->canonicalId, $candidate->parentId, null, [], $modelContext->reasons, $candidate->source, false, [...$candidate->warnings, ...$modelContext->warnings]);
        return new StructuralContext('variant', $entity->canonicalId, $candidate->parentId, $modelContext->brandId, [], [], $candidate->source, false, [...$candidate->warnings, ...$modelContext->warnings]);
    }

    /** @return list<GraphEdge> */
    private function outgoing(string $type, string $id, string $predicate): array
    {
        return array_values(array_filter($this->graph->findOutgoing(new NodeReference($type, $id), $predicate, 0, 200)['items'], static fn (GraphEdge $edge): bool => $edge->isActive()));
    }

    private function active(?AuthorityEntity $entity, string $type): bool { return $entity !== null && $entity->entityType === $type && $entity->active(); }

    /** @return list<string> */
    private function compatibilityReasons(StructuralParentCompatibility $candidate): array
    {
        $legacy = in_array($candidate->classification, ['MISSING_PARENT', 'MALFORMED_REFERENCE', 'PARENT_ENTITY_MISSING', 'PARENT_INACTIVE'], true) ? 'STRUCTURAL_PARENT_MISSING' : $candidate->classification;
        return array_values(array_unique([$legacy, $candidate->classification]));
    }
}
