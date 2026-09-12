<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Application\Entity\EntityProfileResolver;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Bounded profile recipe for the derived Brand × Clock-Type projection.
 * It deliberately does not use RelatedSemanticQuery's global hop budget or
 * persist a shortcut relation.
 */
final class ClockTypeDerivedRelationshipQuery
{
    public function __construct(private GraphService $graph, private AuthorityRepository $authority, private EntityProfileResolver $profiles = new EntityProfileResolver()) {}

    /** @return array<string,mixed> */
    public function forBrand(string $brandUuid): array
    {
        $brand = $this->authority->findByCanonicalId($brandUuid);
        if (!$brand instanceof AuthorityEntity || !$brand->active() || $brand->entityType !== 'brand') return ['status' => 'UNAVAILABLE', 'items' => [], 'diagnostics' => ['BRAND_NOT_AVAILABLE']];
        try {
            $paths = [];
            foreach ($this->items($this->graph->findIncoming(new NodeReference('brand', $brandUuid), 'model_of', 0, 200, false, 'model')) as $edge) {
                if (!$edge->isActive()) continue;
                $model = $this->authority->findByCanonicalId($edge->source->reference->endpoint_key);
                if (!$model instanceof AuthorityEntity || !$model->active() || $model->entityType !== 'model') continue;
                $paths = [...$paths, ...$this->classificationPaths($model, [['predicate' => 'model_of', 'source' => $model->canonicalId, 'target' => $brand->canonicalId]])];
                foreach ($this->items($this->graph->findIncoming(new NodeReference('model', $model->canonicalId), 'variant_of', 0, 200, false, 'variant')) as $variantEdge) {
                    if (!$variantEdge->isActive()) continue;
                    $variant = $this->authority->findByCanonicalId($variantEdge->source->reference->endpoint_key);
                    if (!$variant instanceof AuthorityEntity || !$variant->active() || $variant->entityType !== 'variant') continue;
                    $paths = [...$paths, ...$this->classificationPaths($variant, [
                        ['predicate' => 'variant_of', 'source' => $variant->canonicalId, 'target' => $model->canonicalId],
                        ['predicate' => 'model_of', 'source' => $model->canonicalId, 'target' => $brand->canonicalId],
                    ])];
                }
            }
            return $this->aggregate($paths);
        } catch (\Throwable) { return ['status' => 'UNAVAILABLE', 'items' => [], 'diagnostics' => ['GRAPH_RESEARCH_UNAVAILABLE']]; }
    }

    /** @return array<string,mixed> */
    public function forClockType(string $classificationUuid): array
    {
        $type = $this->authority->findByCanonicalId($classificationUuid);
        $profile = $type instanceof AuthorityEntity ? $this->profiles->resolveProfile($type) : null;
        if (!$type instanceof AuthorityEntity || !$type->active() || $type->entityType !== 'classification' || $profile === null || !$profile->resolved() || $profile->profileKey !== 'clock_type') return ['status' => 'UNAVAILABLE', 'items' => [], 'diagnostics' => ['CLOCK_TYPE_NOT_AVAILABLE']];
        try {
            $paths = [];
            foreach ($this->items($this->graph->findIncoming(new NodeReference('classification', $classificationUuid), 'classified_as', 0, 200, false, null)) as $classificationEdge) {
                if (!$classificationEdge->isActive()) continue;
                $source = $classificationEdge->source->reference;
                if ($source->endpoint_type === 'model') $paths = [...$paths, ...$this->brandsForModel($source->endpoint_key, [['predicate' => 'classified_as', 'source' => $source->endpoint_key, 'target' => $classificationUuid]])];
                if ($source->endpoint_type === 'variant') {
                    $paths = [...$paths, ...$this->brandsForVariant($source->endpoint_key, [['predicate' => 'classified_as', 'source' => $source->endpoint_key, 'target' => $classificationUuid]])];
                }
            }
            return $this->aggregate($paths);
        } catch (\Throwable) { return ['status' => 'UNAVAILABLE', 'items' => [], 'diagnostics' => ['GRAPH_RESEARCH_UNAVAILABLE']]; }
    }

    /** @param list<array<string,mixed>> $prefix @return list<array<string,mixed>> */
    private function classificationPaths(AuthorityEntity $source, array $prefix): array
    {
        $paths = [];
        foreach ($this->items($this->graph->findOutgoing(new NodeReference($source->entityType, $source->canonicalId), 'classified_as', 0, 200, false, 'classification')) as $edge) {
            if (!$edge->isActive()) continue;
            $target = $this->authority->findByCanonicalId($edge->target->reference->endpoint_key);
            if (!$target instanceof AuthorityEntity || !$target->active() || $target->entityType !== 'classification') continue;
            $profile = $this->profiles->resolveProfile($target);
            if (!$profile->resolved() || $profile->profileKey !== 'clock_type') continue;
            $paths[] = ['entity' => $target, 'path' => [...$prefix, ['predicate' => 'classified_as', 'source' => $source->canonicalId, 'target' => $target->canonicalId]]];
        }
        return $paths;
    }

    /** @param list<array<string,mixed>> $prefix */
    private function brandsForModel(string $modelUuid, array $prefix): array
    {
        $model = $this->authority->findByCanonicalId($modelUuid);
        if (!$model instanceof AuthorityEntity || !$model->active() || $model->entityType !== 'model') return [];
        $out = [];
        foreach ($this->items($this->graph->findOutgoing(new NodeReference('model', $modelUuid), 'model_of', 0, 200, false, 'brand')) as $edge) {
            if (!$edge->isActive()) continue;
            $brand = $this->authority->findByCanonicalId($edge->target->reference->endpoint_key);
            if ($brand instanceof AuthorityEntity && $brand->active() && $brand->entityType === 'brand') $out[] = ['entity' => $brand, 'path' => [...$prefix, ['predicate' => 'model_of', 'source' => $modelUuid, 'target' => $brand->canonicalId]]];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $prefix */
    private function brandsForVariant(string $variantUuid, array $prefix): array
    {
        $variant = $this->authority->findByCanonicalId($variantUuid);
        if (!$variant instanceof AuthorityEntity || !$variant->active() || $variant->entityType !== 'variant') return [];
        $out = [];
        foreach ($this->items($this->graph->findOutgoing(new NodeReference('variant', $variantUuid), 'variant_of', 0, 200, false, 'model')) as $edge) {
            if (!$edge->isActive()) continue;
            $model = $this->authority->findByCanonicalId($edge->target->reference->endpoint_key);
            if (!$model instanceof AuthorityEntity || !$model->active() || $model->entityType !== 'model') continue;
            $out = [...$out, ...$this->brandsForModel($model->canonicalId, [...$prefix, ['predicate' => 'variant_of', 'source' => $variantUuid, 'target' => $model->canonicalId]])];
        }
        return $out;
    }

    /** @param array<string,mixed> $result @return list<object> */
    private function items(array $result): array { return array_values(array_filter((array) ($result['items'] ?? []), static fn (mixed $edge): bool => is_object($edge) && property_exists($edge, 'source') && property_exists($edge, 'target'))); }

    /** @param list<array{entity:AuthorityEntity,path:list<array<string,string>>}> $paths @return array<string,mixed> */
    private function aggregate(array $paths): array
    {
        $grouped = [];
        foreach ($paths as $row) {
            $id = $row['entity']->canonicalId;
            $grouped[$id] ??= ['entity' => $row['entity'], 'paths' => []];
            $grouped[$id]['paths'][] = $row['path'];
        }
        $items = [];
        foreach ($grouped as $row) {
            $pathsForEntity = $row['paths'];
            usort($pathsForEntity, static fn (array $a, array $b): int => count($a) <=> count($b));
            $items[] = ['entity_type' => $row['entity']->entityType, 'canonical_id' => $row['entity']->canonicalId, 'stable_key' => $row['entity']->stableKey, 'name' => $row['entity']->canonicalName, 'relationship_class' => 'DERIVED', 'best_path' => $pathsForEntity[0], 'alternative_paths' => array_slice($pathsForEntity, 1), 'ranking_reason' => 'SHORTEST_REGISTERED_CANONICAL_PATH'];
        }
        return ['status' => $items === [] ? 'AVAILABLE_EMPTY' : 'AVAILABLE_WITH_ITEMS', 'items' => $items, 'diagnostics' => []];
    }
}
