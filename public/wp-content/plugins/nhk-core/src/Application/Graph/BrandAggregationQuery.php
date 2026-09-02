<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Application\Entity\{PublicEntityEligibilityPolicy, PublicRouteResolver};
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{GraphEdge, NodeReference};

final class BrandAggregationQuery
{
    /** @var array<string,string> */
    private const GROUPS = [
        'model' => 'models', 'variant' => 'variants', 'movement' => 'movements', 'music' => 'music',
        'component' => 'components', 'classification' => 'classifications', 'specimen' => 'specimens', 'product' => 'products',
    ];

    public function __construct(private GraphService $graph, private AuthorityRepository $authority, private EntityTypeRegistry $types, private ?PublicRouteResolver $routes = null, private ?PublicEntityEligibilityPolicy $eligibility = null) {}

    /** @return array<string,list<array<string,mixed>>> */
    public function forBrand(string $brandId): array
    {
        $result = array_fill_keys(['models', 'variants', 'movements', 'music', 'components', 'classifications', 'specimens', 'products', 'knowledge', 'media', 'videos', 'sources', 'evidence'], []);
        $brand = $this->authority->findByCanonicalId($brandId);
        if (!$brand || !$brand->active() || $brand->entityType !== 'brand') return $result;

        $buckets = array_fill_keys(array_values(self::GROUPS), []);
        $models = $this->edges('brand', $brandId, false, 'model_of');
        foreach ($models as $modelEdge) {
            $model = $this->entity($modelEdge->source->reference->endpoint_type, $modelEdge->source->reference->endpoint_key);
            if (!$model || $model->entityType !== 'model' || !$model->active()) continue;
            $this->add($buckets, $model, 'DIRECT', ['model_of']);
            foreach ($this->edges('model', $model->canonicalId, false, 'variant_of') as $variantEdge) {
                $variant = $this->entity('variant', $variantEdge->source->reference->endpoint_key);
                if (!$variant || !$variant->active()) continue;
                $this->add($buckets, $variant, 'DERIVED', ['variant_of', 'model_of']);
            }
        }

        // A keyword match is not a relation. Only an existing, active Graph about edge
        // can place a shared Authority record on this Brand projection.
        foreach ([$this->graph->findOutgoing(new NodeReference('brand', $brandId), 'about', 0, 200), $this->graph->findIncoming(new NodeReference('brand', $brandId), 'about', 0, 200)] as $page) {
            foreach ($page['items'] as $edge) {
                if (!$edge->isActive()) continue;
                $node = $edge->source->reference->key() === 'brand:' . $brandId ? $edge->target->reference : $edge->source->reference;
                if ($node->key() === 'brand:' . $brandId) continue;
                $entity = $this->entity($node->endpoint_type, $node->endpoint_key);
                if ($entity !== null) $this->add($buckets, $entity, 'DIRECT', ['about']);
            }
        }

        foreach ($buckets as $group => $items) $result[$group] = array_values($items);
        return $result;
    }

    /** @return list<GraphEdge> */
    private function edges(string $type, string $id, bool $outgoing, string $predicate): array
    {
        $page = $outgoing ? $this->graph->findOutgoing(new NodeReference($type, $id), $predicate, 0, 200) : $this->graph->findIncoming(new NodeReference($type, $id), $predicate, 0, 200);
        return array_values(array_filter($page['items'], static fn (GraphEdge $edge): bool => $edge->isActive()));
    }

    private function entity(string $type, string $id): ?AuthorityEntity
    {
        if (!$this->types->has($type)) return null;
        $entity = $this->authority->findByCanonicalId($id);
        if (!$entity || $entity->entityType !== $type || !$entity->active()) return null;
        if ($this->eligibility !== null && !$this->eligibility->evaluate($entity)->eligible) return null;
        return $entity;
    }

    /** @return array<string,mixed> */
    private function item(AuthorityEntity $entity, string $kind, array $path): array
    {
        $item = ['type' => $entity->entityType, 'name' => $entity->canonicalName, 'origin' => ['kind' => $kind, 'path' => $path, 'hop_count' => count($path)]];
        $url = $this->routes?->path($entity);
        if ($url !== null) $item['url'] = $url;
        return $item;
    }

    /** @param array<string,array<string,array<string,mixed>>> $buckets */
    private function add(array &$buckets, AuthorityEntity $entity, string $kind, array $path): void
    {
        $group = self::GROUPS[$entity->entityType] ?? null;
        if ($group === null) return;
        $candidate = $this->item($entity, $kind, $path);
        $existing = $buckets[$group][$entity->canonicalId] ?? null;
        if ($existing !== null && !$this->isBetter($candidate, $existing)) return;
        $buckets[$group][$entity->canonicalId] = $candidate;
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $existing */
    private function isBetter(array $candidate, array $existing): bool
    {
        $candidateKind = ($candidate['origin']['kind'] ?? '') === 'DIRECT' ? 0 : 1;
        $existingKind = ($existing['origin']['kind'] ?? '') === 'DIRECT' ? 0 : 1;
        if ($candidateKind !== $existingKind) return $candidateKind < $existingKind;
        return (int) ($candidate['origin']['hop_count'] ?? 99) < (int) ($existing['origin']['hop_count'] ?? 99);
    }
}
