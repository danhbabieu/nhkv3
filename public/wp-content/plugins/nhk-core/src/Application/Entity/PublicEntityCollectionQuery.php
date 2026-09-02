<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Application\Graph\BrandAggregationQuery;

final class PublicEntityCollectionQuery
{
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types, private PublicIdentityContract $identity, private PublicEntityEligibilityPolicy $eligibility, private PublicRouteResolver $routes, private ?BrandAggregationQuery $aggregation = null, private ?\Closure $availability = null) {}

    public function types(): EntityTypeRegistry { return $this->types; }

    /** @return array{available:bool,type:string,page:int,per_page:int,total:int,query:string,items:list<array<string,mixed>>} */
    public function archive(string $type, int $page = 1, int $perPage = 24, string $query = ''): array
    {
        $page = max(1, $page); $perPage = min(100, max(1, $perPage)); $query = trim($query); $items = [];
        if (!$this->isAvailable() || !$this->types->has($type)) return ['available' => $this->isAvailable(), 'type' => $type, 'page' => $page, 'per_page' => $perPage, 'total' => 0, 'query' => $query, 'items' => []];
        foreach ($this->authority->listByType($type, true) as $entity) {
            $item = $this->item($entity, $query);
            if ($item !== null) $items[] = $item;
        }
        return ['available' => true, 'type' => $type, 'page' => $page, 'per_page' => $perPage, 'total' => count($items), 'query' => $query, 'items' => array_slice($items, ($page - 1) * $perPage, $perPage)];
    }

    /** @return array<string,mixed>|null */
    public function detail(string $type, string $key): ?array
    {
        if (!$this->isAvailable() || !$this->types->has($type)) return null;
        if (preg_match('/^[0-9a-f-]{36}$/i', $key) === 1 && !UuidCodec::isValid($key)) return null;
        $entity = preg_match('/^[0-9a-f-]{36}$/i', $key) === 1 ? $this->authority->findByCanonicalId($key) : $this->authority->findByStableKey($type, $key);
        return $entity && $entity->entityType === $type ? $this->item($entity) : null;
    }

    public function publicPath(AuthorityEntity $entity): ?string { return $this->routes->path($entity); }

    /** @param list<string> $segments */
    public function resolvePublic(string $type, array $segments): ?AuthorityEntity { return $this->routes->resolve($type, $segments); }

    public function publicPathForKey(string $type, string $key): ?string
    {
        $item = $this->detail($type, $key);
        return is_array($item) ? (string) ($item['url'] ?? '') ?: null : null;
    }

    public function stableKeyForPublicSlug(string $type, string $slug): ?string
    {
        if (!$this->types->has($type)) return null;
        $matches = [];
        foreach ($this->authority->listByType($type) as $entity) if (PublicRouteResolver::slug($entity->canonicalName) === trim($slug) && $this->eligibility->evaluate($entity)->eligible) $matches[] = $entity->stableKey;
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array<string,mixed>|null */
    private function item(AuthorityEntity $entity, string $query = ''): ?array
    {
        $decision = $this->eligibility->evaluate($entity);
        if (!$decision->eligible) return null;
        $identity = $this->identity->resolve($entity);
        $path = $identity === null ? null : $this->routes->path($entity);
        if ($identity === null || $path === null) return null;
        $payload = $this->identity->payload($entity);
        if ($query !== '' && !$this->matches($query, $entity->canonicalName, $entity->stableKey, $this->json($payload))) return null;
        $item = [...$identity, 'payload' => $payload, 'url' => $path];
        if ($this->aggregation !== null && $entity->entityType === 'brand') $item['aggregation'] = $this->aggregation->forBrand($entity->canonicalId);
        return $item;
    }

    private function isAvailable(): bool { return $this->availability === null || (bool) ($this->availability)(); }

    private function matches(string $query, string ...$values): bool { foreach ($values as $value) if ((function_exists('mb_stripos') ? mb_stripos($value, $query) : stripos($value, $query)) !== false) return true; return false; }
    private function json(array $value): string { return function_exists('wp_json_encode') ? (string) wp_json_encode($value) : (string) json_encode($value); }
}
