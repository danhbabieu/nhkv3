<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Shared\Migration\MigrationStatus;

final class EntityPageQuery
{
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types, private ?RelatedContentQuery $related = null, private ?MigrationStatus $status = null) {}

    public function detail(string $type, string $key): ?array
    {
        if (!$this->types->has($type) || !$this->available()) return null;
        $entity = preg_match('/^[0-9a-f-]{36}$/i', $key) === 1 ? $this->authority->findByCanonicalId($key) : $this->authority->findByStableKey($type, $key);
        if (!$entity || $entity->entityType !== $type || !$entity->active()) return null;
        $serialized = $this->serialize($entity); $serialized['related'] = $this->related?->forEntity($type, $entity->canonicalId) ?? ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []];
        return $serialized;
    }

    /** Return a canonical stable key for a legacy visitor-facing slug only when the match is unambiguous. */
    public function stableKeyForPublicSlug(string $type, string $slug): ?string
    {
        if (!$this->types->has($type) || !$this->available()) return null;
        $slug = trim($slug);
        if ($slug === '') return null;
        $matches = [];
        foreach ($this->authority->listByType($type) as $entity) {
            if (!$entity->active() || $this->publicSlug($entity->canonicalName) !== $slug) continue;
            $matches[] = $entity->stableKey;
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array{type:string,page:int,per_page:int,total:int,query:string,items:list<array<string,mixed>>} */
    public function archive(string $type, int $page = 1, int $perPage = 24, string $query = ''): array
    {
        if (!$this->types->has($type) || !$this->available()) return ['type' => $type, 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'query' => $query, 'items' => []];
        $query = trim($query); $items = [];
        foreach ($this->authority->listByType($type) as $entity) {
            $publicPayload = array_intersect_key($entity->payload, array_fill_keys($this->types->get($entity->entityType)->allowedFields, true));
            if ($query !== '' && !$this->matches($query, $entity->canonicalName, $entity->stableKey, $this->json($publicPayload))) continue;
            $items[] = $this->serialize($entity);
        }
        $page = max(1, $page); $perPage = min(100, max(1, $perPage)); $total = count($items);
        return ['type' => $type, 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'query' => $query, 'items' => array_slice($items, ($page - 1) * $perPage, $perPage)];
    }

    private function serialize(AuthorityEntity $entity): array { $definition = $this->types->get($entity->entityType); $payload = array_intersect_key($entity->payload, array_fill_keys($definition->allowedFields, true)); return ['id' => $entity->canonicalId, 'type' => $entity->entityType, 'stable_key' => $entity->stableKey, 'name' => $entity->canonicalName, 'payload' => $payload]; }
    private function available(): bool { return !$this->status || $this->status->authorityStorageReady(); }
    private function matches(string $query, string ...$values): bool { foreach ($values as $value) if ((function_exists('mb_stripos') ? mb_stripos($value, $query) : stripos($value, $query)) !== false) return true; return false; }
    private function json(array $value): string { return function_exists('wp_json_encode') ? (string) wp_json_encode($value) : (string) json_encode($value); }
    private function publicSlug(string $value): string
    {
        if (function_exists('sanitize_title')) return (string) sanitize_title($value);
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim($value, '-');
    }
}
