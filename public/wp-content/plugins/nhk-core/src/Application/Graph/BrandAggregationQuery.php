<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{GraphEdge, NodeReference};

final class BrandAggregationQuery
{
    public function __construct(private GraphService $graph, private AuthorityRepository $authority, private EntityTypeRegistry $types, private ?PublicRouteResolver $routes = null) {}

    /** @return array<string,list<array<string,mixed>>> */
    public function forBrand(string $brandId): array
    {
        $result = array_fill_keys(['models', 'variants', 'movements', 'music', 'components', 'classifications', 'specimens', 'products', 'knowledge', 'media', 'videos', 'sources', 'evidence'], []);
        $brand = $this->authority->findByCanonicalId($brandId);
        if (!$brand || !$brand->active() || $brand->entityType !== 'brand') return $result;
        $models = $this->edges('brand', $brandId, false, 'model_of');
        foreach ($models as $modelEdge) {
            $model = $this->entity($modelEdge->source->reference->endpoint_type, $modelEdge->source->reference->endpoint_key);
            if (!$model || $model->entityType !== 'model' || !$model->active()) continue;
            $result['models'][] = $this->item($model, 'DIRECT', ['model_of']);
            foreach ($this->edges('model', $model->canonicalId, false, 'variant_of') as $variantEdge) {
                $variant = $this->entity('variant', $variantEdge->source->reference->endpoint_key);
                if (!$variant || !$variant->active()) continue;
                $result['variants'][] = $this->item($variant, 'DERIVED', ['variant_of', 'model_of']);
                foreach ($this->edges('variant', $variant->canonicalId, true, 'uses_movement') as $movementEdge) {
                    $movement = $this->entity('movement', $movementEdge->target->reference->endpoint_key);
                    if (!$movement || !$movement->active()) continue;
                    $this->appendUnique($result['movements'], $this->item($movement, 'DERIVED', ['variant_of', 'uses_movement']));
                    foreach ($this->edges('movement', $movement->canonicalId, true, 'supports_music') as $musicEdge) {
                        $music = $this->entity('music', $musicEdge->target->reference->endpoint_key);
                        if ($music && $music->active()) $this->appendUnique($result['music'], $this->item($music, 'DERIVED', ['variant_of', 'uses_movement', 'supports_music']));
                    }
                }
                foreach ($this->edges('variant', $variant->canonicalId, true, 'configured_with_music') as $musicEdge) {
                    $music = $this->entity('music', $musicEdge->target->reference->endpoint_key);
                    if ($music && $music->active()) $this->appendUnique($result['music'], $this->item($music, 'DIRECT', ['configured_with_music']));
                }
            }
        }
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
        return $entity && $entity->entityType === $type ? $entity : null;
    }

    /** @return array<string,mixed> */
    private function item(AuthorityEntity $entity, string $kind, array $path): array
    {
        $item = ['type' => $entity->entityType, 'name' => $entity->canonicalName, 'origin' => ['kind' => $kind, 'path' => $path]];
        $url = $this->routes?->path($entity);
        if ($url !== null) $item['url'] = $url;
        return $item;
    }

    /** @param list<array<string,mixed>> $items */
    private function appendUnique(array &$items, array $item): void { foreach ($items as $existing) if (($existing['id'] ?? null) === $item['id']) return; $items[] = $item; }
}
