<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Graph\BrandAggregationQuery;
use NHK\Core\Application\Knowledge\EntityKnowledgeProjection;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Application\Presentation\LatestFirstOrder;
use NHK\Core\Application\Presentation\PresentationReadiness;

final class PublicEntityCollectionQuery
{
    public function __construct(
        private AuthorityRepository $authority,
        private EntityTypeRegistry $types,
        private PublicIdentityContract $identity,
        private PublicEntityEligibilityPolicy $eligibility,
        private PublicRouteResolver $routes,
        private ?BrandAggregationQuery $aggregation = null,
        private ?\Closure $availability = null,
        private ?EntityMediaProjection $entityMedia = null,
        private ?EntityKnowledgeProjection $entityKnowledge = null,
    ) {}

    public function types(): EntityTypeRegistry { return $this->types; }

    /** @return array{available:bool,type:string,profile_key:string,page:int,per_page:int,total:int,query:string,items:list<array<string,mixed>>} */
    public function archiveProfile(string $profileKey, int $page = 1, int $perPage = 24, string $query = ''): array
    {
        $page = max(1, $page); $perPage = min(100, max(1, $perPage)); $query = trim($query);
        $definition = (new EntityProfileRegistry())->get($profileKey);
        $type = $definition instanceof EntityProfileDefinition ? (string) ($definition->matchingRule['entity_type'] ?? '') : '';
        $empty = ['available' => $this->isAvailable(), 'type' => $type, 'profile_key' => $profileKey, 'page' => $page, 'per_page' => $perPage, 'total' => 0, 'query' => $query, 'items' => []];
        if (!$this->isAvailable() || !$definition instanceof EntityProfileDefinition || $type === '') return $empty;

        $items = [];
        $resolver = new EntityProfileResolver();
        foreach ($this->authority->listByType($type, true) as $entity) {
            $resolution = $resolver->resolveProfile($entity);
            if (!$entity->active() || $resolution->profileKey !== $profileKey || !in_array($resolution->status, ['RESOLVED', EntityProfileResolution::COMPATIBILITY_READ], true)) continue;
            $item = $this->item($entity, $query, false, true, $resolution);
            if ($item !== null) $items[] = $item;
        }
        $items = LatestFirstOrder::sort($items, static fn (array $item): ?string => null, static fn (array $item): ?string => $item['_created_at'] ?? null, static fn (array $item): string => (string) ($item['canonical_id'] ?? $item['url'] ?? $item['name'] ?? ''));
        $items = array_map(static function (array $item): array { unset($item['_created_at']); return $item; }, $items);
        $empty['available'] = true;
        $empty['total'] = count($items);
        $empty['items'] = array_slice($items, ($page - 1) * $perPage, $perPage);
        return $empty;
    }

    /** @return array{available:bool,type:string,page:int,per_page:int,total:int,query:string,items:list<array<string,mixed>>} */
    public function archive(string $type, int $page = 1, int $perPage = 24, string $query = ''): array
    {
        $page = max(1, $page); $perPage = min(100, max(1, $perPage)); $query = trim($query); $items = [];
        if (!$this->isAvailable() || !$this->types->has($type)) return ['available' => $this->isAvailable(), 'type' => $type, 'page' => $page, 'per_page' => $perPage, 'total' => 0, 'query' => $query, 'items' => []];
        foreach ($this->authority->listByType($type, true) as $entity) {
            $item = $this->item($entity, $query, false);
            if ($item !== null) $items[] = $item;
        }
        $items = LatestFirstOrder::sort($items, static fn (array $item): ?string => null, static fn (array $item): ?string => $item['_created_at'] ?? null, static fn (array $item): string => (string) ($item['canonical_id'] ?? $item['url'] ?? $item['name'] ?? ''));
        $items = array_map(static function (array $item): array { unset($item['_created_at']); return $item; }, $items);
        return ['available' => true, 'type' => $type, 'page' => $page, 'per_page' => $perPage, 'total' => count($items), 'query' => $query, 'items' => array_slice($items, ($page - 1) * $perPage, $perPage)];
    }

    /** @return array<string,mixed>|null */
    public function detail(string $type, string $key): ?array
    {
        if (!$this->isAvailable() || !$this->types->has($type)) return null;
        $entity = $this->resolvePublicSlug($type, $key);
        $item = $entity === null ? null : $this->item($entity, '', true);
        return $item === null ? null : $this->withoutOrderingMetadata($item);
    }

    public function publicPath(AuthorityEntity $entity): ?string { return $this->routes->path($entity); }

    public function resolvePublicSlug(string $type, string $slug): ?AuthorityEntity
    {
        if (!$this->isAvailable() || !$this->types->has($type) || trim($slug) === '') return null;
        $matches = [];
        $resolved = $this->routes->resolve($type, $this->routeSegments($type, $slug));
        if ($resolved !== null && $resolved->entityType === $type && $this->item($resolved, '', false) !== null) $matches[] = $resolved;
        return count($matches) === 1 ? $matches[0] : null;
    }

    public function publicPathForStableKey(string $type, string $stableKey): ?string
    {
        if (!$this->isAvailable() || !$this->types->has($type)) return null;
        $entity = $this->authority->findByStableKey($type, $stableKey);
        if ($entity === null || $entity->entityType !== $type) return null;
        $item = $this->item($entity, '', false);
        return $item === null ? null : (($item['url'] ?? '') !== '' ? (string) $item['url'] : null);
    }

    public function detailForEntity(AuthorityEntity $entity): ?array
    {
        if (!$this->isAvailable() || !$this->types->has($entity->entityType)) return null;
        $item = $this->item($entity, '', true);
        return $item === null ? null : $this->withoutOrderingMetadata($item);
    }

    /** @param list<string> $segments */
    public function resolvePublic(string $type, array $segments): ?AuthorityEntity { return $this->routes->resolve($type, $segments); }
    public function publicPathForKey(string $type, string $key): ?string { return $this->publicPathForStableKey($type, $key); }

    public function stableKeyForPublicSlug(string $type, string $slug): ?string
    {
        if (!$this->types->has($type)) return null;
        $resolved = $this->routes->resolve($type, $this->routeSegments($type, $slug));
        return $resolved !== null && $resolved->entityType === $type && $this->eligibility->evaluate($resolved)->eligible ? $resolved->stableKey : null;
    }

    /** @return array<string,mixed>|null */
    private function item(AuthorityEntity $entity, string $query = '', bool $detail = false, bool $requirePersistedIdentity = false, ?EntityProfileResolution $profileResolution = null): ?array
    {
        $decision = $this->eligibility->evaluate($entity);
        if (!$decision->eligible) return null;
        $identity = $requirePersistedIdentity ? $this->identity->resolvePersisted($entity) : $this->identity->resolve($entity);
        $path = $identity === null ? null : $this->routes->path($entity);
        if ($identity === null || $path === null) return null;
        $payload = $this->identity->payload($entity);
        if ($query !== '' && !$this->matches($query, $entity->canonicalName, $entity->stableKey, $this->json($payload))) return null;
        $url = (new PublicSeoProjection())->project(['path' => $path, 'eligible' => true], ['type' => 'Entity'])['card'];
        $item = [...$identity, 'payload' => $payload, 'url' => $url, '_created_at' => $entity->createdAt];
        $profile = $profileResolution ?? (new EntityProfileResolver())->resolveProfile($entity);
        if ($profile->resolved() || $profile->status === EntityProfileResolution::COMPATIBILITY_READ) {
            $definition = (new EntityProfileRegistry())->get((string) $profile->profileKey);
            if ($definition instanceof EntityProfileDefinition) $item += ['profile_key' => $definition->key, 'profile_label' => $definition->visitorLabel, 'profile_badge' => $definition->toArray()['admin_badge'], 'profile_status' => $profile->status];
        }
        if ($this->entityMedia !== null) {
            $media = $this->entityMedia->forEntity($entity->entityType, $entity->canonicalId);
            $item['media'] = [
                'representative' => $this->publicMediaItem($media['representative'] ?? null),
                'evidence' => array_values(array_filter(array_map(fn(array $entry): ?array => $this->publicMediaItem($entry), $media['evidence'] ?? []))),
                'gallery' => array_values(array_filter(array_map(fn(array $entry): ?array => $this->publicMediaItem($entry), $media['gallery'] ?? []))),
            ];
        }
        $description = trim((string) ($payload['description'] ?? $payload['summary'] ?? ''));
        $item['description'] = $description;
        if ($profile->profileKey === 'clock_type') {
            $hasRepresentative = is_array($item['media']['representative'] ?? null) && trim((string) ($item['media']['representative']['url'] ?? '')) !== '';
            $readiness = PresentationReadiness::evaluate(
                ['active' => $entity->active()],
                ['route' => $path, 'content' => ['description' => $description, 'representative_media' => $hasRepresentative], 'public_eligible' => $decision->eligible],
            );
            $item['presentation_readiness'] = ['status' => $readiness->presentationStatus(), 'reasons' => $readiness->reasons()];
        }
        if ($detail && $this->entityKnowledge !== null) $item['knowledge'] = $this->entityKnowledge->forSubject($entity->canonicalId);
        if ($detail && $this->aggregation !== null && $entity->entityType === 'brand') $item['aggregation'] = $this->aggregation->forBrand($entity->canonicalId);
        return $item;
    }

    private function publicMediaItem(?array $item): ?array
    {
        if ($item === null || trim((string) ($item['url'] ?? '')) === '') return null;
        return [
            'url' => (string) $item['url'],
            'alt' => (string) ($item['alt'] ?? ''),
            'role' => (string) ($item['role'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function withoutOrderingMetadata(array $item): array
    {
        unset($item['_created_at']);
        return $item;
    }

    private function isAvailable(): bool { return $this->availability === null || (bool) ($this->availability)(); }
    private function matches(string $query, string ...$values): bool { foreach ($values as $value) if ((function_exists('mb_stripos') ? mb_stripos($value, $query) : stripos($value, $query)) !== false) return true; return false; }
    private function json(array $value): string { return function_exists('wp_json_encode') ? (string) wp_json_encode($value) : (string) json_encode($value); }
    /** @return list<string> */
    private function routeSegments(string $type, string $slug): array
    {
        return PublicRouteResolver::routeSegments($type, $slug);
    }
}
