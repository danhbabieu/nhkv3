<?php
declare(strict_types=1);

namespace NHK\Core\Application\Catalogue;

use NHK\Core\Application\Entity\{PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{GraphEdge, NodeReference};

/** Bounded public catalogue read model for physical objects and offers. */
final class SpecimenCatalogueQuery
{
    public function __construct(
        private AuthorityRepository $authority,
        private EntityTypeRegistry $types,
        private GraphService $graph,
        private PublicRouteResolver $routes,
        private ?\Closure $knowledge = null,
        private ?\Closure $dictionary = null,
        private ?\Closure $resources = null,
        private ?\Closure $media = null,
    ) {}

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function archive(string $type, int $page = 1, int $perPage = 24, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        if (!in_array($type, ['specimen', 'product'], true) || !$this->types->has($type)) return $this->empty($type, $page, $perPage, $filters, 'UNSUPPORTED_CATALOGUE_TYPE');
        $items = [];
        foreach ($this->authority->listByType($type, true) as $entity) {
            if (!$entity instanceof AuthorityEntity || !$entity->active()) continue;
            $item = $this->item($entity, false, $filters);
            if ($item === null || !$this->matchesFilters($item, $filters)) continue;
            $items[] = $item;
        }
        usort($items, static fn (array $left, array $right): int => [$left['name'], $left['type'], $left['url']] <=> [$right['name'], $right['type'], $right['url']]);
        return ['status' => 'available', 'type' => $type, 'page' => $page, 'per_page' => $perPage, 'total' => count($items), 'filters' => $filters, 'items' => array_slice($items, ($page - 1) * $perPage, $perPage)];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed>|null */
    public function detail(string $type, string $stableKey, array $filters = []): ?array
    {
        if (!in_array($type, ['specimen', 'product'], true) || !$this->types->has($type)) return null;
        $entity = $this->authority->findByStableKey($type, trim($stableKey));
        return $entity instanceof AuthorityEntity && $entity->active() ? $this->item($entity, true, $filters) : null;
    }

    /** @return array<string,mixed> */
    private function item(AuthorityEntity $entity, bool $detail, array $filters): ?array
    {
        $url = $this->routes->path($entity);
        if ($url === null) return null;
        $payload = (new PublicIdentityContract($this->types))->payload($entity);
        $item = ['type' => $entity->entityType, 'name' => $entity->canonicalName, 'url' => $url, 'payload' => $payload];
        if ($entity->entityType === 'specimen') {
            $item['identity'] = $this->identityContext($entity);
            $item['products'] = $this->productHistory($entity, $detail || (($filters['include_historical'] ?? false) === true));
        } else {
            $item['specimen'] = $this->listedSpecimen($entity);
            $item['commercial'] = [
                'offer_state' => $payload['offer_state'] ?? null,
                'availability' => $payload['availability'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'price' => $payload['price'] ?? null,
            ];
        }
        if ($detail) {
            $item['knowledge'] = $this->knowledge ? (($this->knowledge)($entity->entityType, $entity->canonicalId) ?: []) : ['status' => 'unavailable'];
            $item['dictionary'] = $this->dictionary ? (($this->dictionary)($entity->entityType, $entity->canonicalId) ?: []) : ['status' => 'unavailable'];
            $item['resources'] = $this->resources ? (($this->resources)($entity->entityType, $entity->canonicalId) ?: []) : ['status' => 'unavailable'];
        }
        if ($this->media !== null) $item['media'] = $this->publicMedia(($this->media)($entity->entityType, $entity->canonicalId));
        return $item;
    }

    /** @return array<string,mixed> */
    private function identityContext(AuthorityEntity $specimen): array
    {
        $edge = $this->firstOutgoing($specimen, 'specimen_of');
        if ($edge === null) return ['status' => 'unresolved', 'model' => null, 'variant' => null, 'brand' => null];
        $target = $this->authority->findByCanonicalId($edge->target->reference->endpoint_key);
        if (!$target instanceof AuthorityEntity || !$target->active()) return ['status' => 'unavailable', 'model' => null, 'variant' => null, 'brand' => null];
        $model = $target->entityType === 'model' ? $target : $this->parentByGraph($target, 'variant_of', 'model');
        $variant = $target->entityType === 'variant' ? $target : null;
        $brand = $model instanceof AuthorityEntity ? $this->parentByGraph($model, 'model_of', 'brand') : null;
        return [
            'status' => 'resolved',
            'target_relation' => 'DIRECT',
            'model' => $this->publicEntity($model, 'DERIVED'),
            'variant' => $this->publicEntity($variant, $variant ? 'DIRECT' : null),
            'brand' => $this->publicEntity($brand, 'DERIVED'),
            'clock_types' => $this->classificationContext($specimen),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function productHistory(AuthorityEntity $specimen, bool $includeHistorical): array
    {
        $items = [];
        foreach ($this->incoming($specimen, 'lists_specimen') as $edge) {
            $product = $this->authority->findByCanonicalId($edge->source->reference->endpoint_key);
            if (!$product instanceof AuthorityEntity || !$product->active()) continue;
            $state = strtolower(trim((string) ($product->payload['offer_state'] ?? $product->payload['availability'] ?? 'active')));
            if (!$includeHistorical && in_array($state, ['sold', 'archived', 'expired', 'retired'], true)) continue;
            $items[] = ['name' => $product->canonicalName, 'url' => $this->routes->path($product), 'state' => $state, 'historical' => in_array($state, ['sold', 'archived', 'expired'], true)];
        }
        usort($items, static fn (array $left, array $right): int => [$left['name'], $left['url']] <=> [$right['name'], $right['url']]);
        return $items;
    }

    /** @return array<string,mixed>|null */
    private function listedSpecimen(AuthorityEntity $product): ?array
    {
        $edge = $this->firstOutgoing($product, 'lists_specimen');
        if ($edge === null) return null;
        $specimen = $this->authority->findByCanonicalId($edge->target->reference->endpoint_key);
        return $specimen instanceof AuthorityEntity && $specimen->active() ? ['name' => $specimen->canonicalName, 'url' => $this->routes->path($specimen), 'relation' => 'DIRECT'] : null;
    }

    /** @return list<array<string,mixed>> */
    private function classificationContext(AuthorityEntity $entity): array
    {
        $items = [];
        foreach ($this->outgoing($entity, 'classified_as') as $edge) {
            $classification = $this->authority->findByCanonicalId($edge->target->reference->endpoint_key);
            $public = $this->publicEntity($classification, 'DIRECT');
            if ($public !== null) $items[] = $public;
        }
        return $items;
    }

    private function parentByGraph(AuthorityEntity $child, string $predicate, string $type): ?AuthorityEntity
    {
        $edge = $this->firstOutgoing($child, $predicate);
        if ($edge === null || $edge->target->reference->endpoint_type !== $type) return null;
        $parent = $this->authority->findByCanonicalId($edge->target->reference->endpoint_key);
        return $parent instanceof AuthorityEntity && $parent->active() ? $parent : null;
    }

    /** @return array<string,mixed>|null */
    private function publicEntity(?AuthorityEntity $entity, ?string $relation): ?array
    {
        if (!$entity || !$entity->active()) return null;
        $path = $this->routes->path($entity);
        return $path === null ? null : ['type' => $entity->entityType, 'name' => $entity->canonicalName, 'url' => $path] + ($relation === null ? [] : ['relation' => $relation]);
    }

    private function firstOutgoing(AuthorityEntity $entity, string $predicate): ?GraphEdge
    {
        try { $items = $this->graph->findOutgoing(new NodeReference($entity->entityType, $entity->canonicalId), $predicate, 0, 200, false)['items'] ?? []; return $items[0] instanceof GraphEdge ? $items[0] : null; } catch (\Throwable) { return null; }
    }

    /** @return list<GraphEdge> */
    private function outgoing(AuthorityEntity $entity, string $predicate): array
    {
        try { return array_values(array_filter((array) ($this->graph->findOutgoing(new NodeReference($entity->entityType, $entity->canonicalId), $predicate, 0, 200, false)['items'] ?? []), static fn (mixed $edge): bool => $edge instanceof GraphEdge)); } catch (\Throwable) { return []; }
    }

    /** @return list<GraphEdge> */
    private function incoming(AuthorityEntity $entity, string $predicate): array
    {
        try { return array_values(array_filter((array) ($this->graph->findIncoming(new NodeReference($entity->entityType, $entity->canonicalId), $predicate, 0, 200, false)['items'] ?? []), static fn (mixed $edge): bool => $edge instanceof GraphEdge)); } catch (\Throwable) { return []; }
    }

    private function matchesFilters(array $item, array $filters): bool
    {
        if (($item['type'] ?? '') === 'product' && ($filters['include_historical'] ?? false) !== true) {
            $state = strtolower(trim((string) ($item['commercial']['offer_state'] ?? $item['commercial']['availability'] ?? 'active')));
            if (in_array($state, ['sold', 'archived', 'expired', 'retired'], true)) return false;
        }
        $identity = is_array($item['identity'] ?? null) ? $item['identity'] : [];
        foreach (['brand', 'model', 'variant'] as $facet) {
            if (!isset($filters[$facet]) || trim((string) $filters[$facet]) === '') continue;
            $value = is_array($identity[$facet] ?? null) ? ($identity[$facet]['name'] ?? '') : '';
            if (strcasecmp(trim((string) $filters[$facet]), trim((string) $value)) !== 0) return false;
        }
        if (isset($filters['clock_type']) && $filters['clock_type'] !== '') {
            $names = array_map(static fn (array $entry): string => (string) ($entry['name'] ?? ''), (array) ($identity['clock_types'] ?? []));
            if (!in_array((string) $filters['clock_type'], $names, true)) return false;
        }
        if (($filters['has_specimen'] ?? null) !== null && (($item['specimen'] ?? null) !== null) !== (bool) $filters['has_specimen']) return false;
        return true;
    }

    private function empty(string $type, int $page, int $perPage, array $filters, string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason, 'type' => $type, 'page' => $page, 'per_page' => $perPage, 'total' => 0, 'filters' => $filters, 'items' => []];
    }

    /** @return array<string,mixed> */
    private function publicMedia(mixed $value): array
    {
        if (!is_array($value)) return ['status' => 'unavailable', 'representative' => null, 'evidence' => [], 'gallery' => []];
        $project = static function (mixed $entry): ?array {
            if (!is_array($entry) || trim((string) ($entry['url'] ?? '')) === '') return null;
            return ['url' => (string) $entry['url'], 'thumbnail_url' => $entry['thumbnail_url'] ?? null, 'alt' => (string) ($entry['alt'] ?? ''), 'role' => (string) ($entry['role'] ?? ''), 'title' => (string) ($entry['title'] ?? ''), 'caption' => (string) ($entry['caption'] ?? '')];
        };
        return ['status' => 'available', 'representative' => $project($value['representative'] ?? null), 'evidence' => array_values(array_filter(array_map($project, (array) ($value['evidence'] ?? [])))), 'gallery' => array_values(array_filter(array_map($project, (array) ($value['gallery'] ?? []))))];
    }
}
