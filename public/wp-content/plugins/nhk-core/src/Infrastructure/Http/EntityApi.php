<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};

final class EntityApi
{
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/entity/(?P<type>[a-z][a-z0-9_]{0,63})/(?P<id>[0-9a-f-]{36})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn (\WP_REST_Request $request) => $this->detail($request)]);
        register_rest_route('nhk/v1', '/entity/(?P<type>[a-z][a-z0-9_]{0,63})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'args' => ['page' => ['default' => 1], 'per_page' => ['default' => 24]], 'callback' => fn (\WP_REST_Request $request) => $this->list($request)]);
    }

    private function detail(\WP_REST_Request $request): array|\WP_Error
    {
        $type = (string) $request['type'];
        if (!$this->types->has($type)) return new \WP_Error('nhk_entity_type_unknown', 'Entity type was not found.', ['status' => 404]);
        $entity = $this->authority->findByCanonicalId((string) $request['id']);
        if (!$entity || $entity->entityType !== $type || !$entity->active()) return new \WP_Error('nhk_entity_not_found', 'Entity was not found.', ['status' => 404]);
        return $this->serialize($entity);
    }

    private function list(\WP_REST_Request $request): array|\WP_Error
    {
        $type = (string) $request['type'];
        if (!$this->types->has($type)) return new \WP_Error('nhk_entity_type_unknown', 'Entity type was not found.', ['status' => 404]);
        $page = max(1, (int) $request['page']); $perPage = min(100, max(1, (int) $request['per_page']));
        $all = $this->authority->listByType($type);
        $items = array_slice($all, ($page - 1) * $perPage, $perPage);
        return ['type' => $type, 'page' => $page, 'per_page' => $perPage, 'total' => count($all), 'items' => array_map($this->serialize(...), $items)];
    }

    private function serialize(AuthorityEntity $entity): array { return ['id' => $entity->canonicalId, 'type' => $entity->entityType, 'stable_key' => $entity->stableKey, 'name' => $entity->canonicalName, 'payload' => $entity->payload, 'active' => $entity->active(), 'revision' => $entity->revision]; }
}
