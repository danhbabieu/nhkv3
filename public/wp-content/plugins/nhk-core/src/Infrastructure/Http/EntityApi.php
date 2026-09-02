<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Application\Entity\PublicEntityCollectionQuery;
use NHK\Core\Application\Entity\PublicIdentityContract;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Shared\Migration\MigrationStatus;

final class EntityApi
{
    public function __construct(private AuthorityRepository $authority, private EntityTypeRegistry $types, private ?MigrationStatus $status = null, private ?PublicEntityCollectionQuery $collection = null) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/entity/(?P<type>[a-z][a-z0-9_]{0,63})/(?P<key>[a-z0-9][a-z0-9._:-]{0,190})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn (\WP_REST_Request $request) => $this->detail($request)]);
        register_rest_route('nhk/v1', '/entity/(?P<type>[a-z][a-z0-9_]{0,63})', ['methods' => 'GET', 'permission_callback' => '__return_true', 'args' => ['page' => ['default' => 1], 'per_page' => ['default' => 24]], 'callback' => fn (\WP_REST_Request $request) => $this->list($request)]);
    }

    private function detail(\WP_REST_Request $request): array|\WP_Error
    {
        if ($error = $this->unavailable()) return $error;
        $type = (string) $request['type'];
        if (!$this->types->has($type)) return new \WP_Error('nhk_entity_type_unknown', 'Entity type was not found.', ['status' => 404]);
        $key = (string) $request['key'];
        $item = $this->collection?->detail($type, $key);
        return $item ?? new \WP_Error('nhk_entity_not_found', 'Entity was not found.', ['status' => 404]);
    }

    private function list(\WP_REST_Request $request): array|\WP_Error
    {
        if ($error = $this->unavailable()) return $error;
        $type = (string) $request['type'];
        if (!$this->types->has($type)) return new \WP_Error('nhk_entity_type_unknown', 'Entity type was not found.', ['status' => 404]);
        $page = max(1, (int) $request['page']); $perPage = min(100, max(1, (int) $request['per_page']));
        if ($this->collection !== null) return $this->collection->archive($type, $page, $perPage);
        $all = array_values(array_filter($this->authority->listByType($type), static fn (AuthorityEntity $entity): bool => $entity->active()));
        $items = array_slice($all, ($page - 1) * $perPage, $perPage);
        return ['type' => $type, 'page' => $page, 'per_page' => $perPage, 'total' => count($all), 'items' => array_map($this->serialize(...), $items)];
    }

    private function serialize(AuthorityEntity $entity): array { $payload = (new PublicIdentityContract($this->types))->payload($entity); return ['type' => $entity->entityType, 'name' => $entity->canonicalName, 'payload' => $payload]; }
    private function unavailable(): ?\WP_Error { return $this->status && !$this->status->authorityStorageReady() ? new \WP_Error('nhk_storage_unavailable', 'Authority storage is not ready.', ['status' => 503]) : null; }
}
