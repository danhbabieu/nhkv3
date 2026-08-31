<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};

final class EntityPageQuery
{
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types) {}

    public function detail(string $type, string $key): ?array
    {
        if (!$this->types->has($type)) return null;
        $entity = preg_match('/^[0-9a-f-]{36}$/i', $key) === 1 ? $this->authority->findByCanonicalId($key) : $this->authority->findByStableKey($type, $key);
        return $entity && $entity->entityType === $type && $entity->active() ? $this->serialize($entity) : null;
    }

    /** @return array{type:string,page:int,per_page:int,total:int,query:string,items:list<array<string,mixed>>} */
    public function archive(string $type, int $page = 1, int $perPage = 24, string $query = ''): array
    {
        if (!$this->types->has($type)) return ['type' => $type, 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'query' => $query, 'items' => []];
        $query = trim($query); $items = [];
        foreach ($this->authority->listByType($type) as $entity) {
            if ($query !== '' && !$this->matches($query, $entity->canonicalName, $entity->stableKey, $this->json($entity->payload))) continue;
            $items[] = $this->serialize($entity);
        }
        $page = max(1, $page); $perPage = min(100, max(1, $perPage)); $total = count($items);
        return ['type' => $type, 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'query' => $query, 'items' => array_slice($items, ($page - 1) * $perPage, $perPage)];
    }

    private function serialize(AuthorityEntity $entity): array { return ['id' => $entity->canonicalId, 'type' => $entity->entityType, 'stable_key' => $entity->stableKey, 'name' => $entity->canonicalName, 'payload' => $entity->payload, 'revision' => $entity->revision]; }
    private function matches(string $query, string ...$values): bool { foreach ($values as $value) if ((function_exists('mb_stripos') ? mb_stripos($value, $query) : stripos($value, $query)) !== false) return true; return false; }
    private function json(array $value): string { return function_exists('wp_json_encode') ? (string) wp_json_encode($value) : (string) json_encode($value); }
}
