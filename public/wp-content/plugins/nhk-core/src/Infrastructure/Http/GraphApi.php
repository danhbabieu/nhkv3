<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Graph\{GraphEdge, NodeReference};
use NHK\Core\Shared\Migration\MigrationStatus;

final class GraphApi
{
    public function __construct(private GraphService $graph, private ?MigrationStatus $status = null) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/graph/(?P<direction>outgoing|incoming)/(?P<endpoint_type>[a-z][a-z0-9_]{0,63})/(?P<endpoint_key>[^/]+)', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'args' => ['predicate' => ['required' => false], 'after' => ['default' => 0], 'limit' => ['default' => 50], 'include_retired' => ['default' => false]],
            'callback' => fn (\WP_REST_Request $request) => $this->list($request),
        ]);
    }

    private function list(\WP_REST_Request $request): array|\WP_Error
    {
        if ($this->status && !$this->status->graphStorageReady()) return new \WP_Error('nhk_graph_storage_unavailable', 'Graph storage is not ready.', ['status' => 503]);
        try {
            $reference = new NodeReference((string) $request['endpoint_type'], rawurldecode((string) $request['endpoint_key']));
            $predicate = trim((string) ($request['predicate'] ?? '')) ?: null;
            $after = max(0, (int) ($request['after'] ?? 0));
            $limit = min(200, max(1, (int) ($request['limit'] ?? 50)));
            $includeRetired = filter_var($request['include_retired'] ?? false, FILTER_VALIDATE_BOOLEAN) && current_user_can('manage_options');
            $direction = (string) $request['direction'];
            $result = $direction === 'incoming'
                ? $this->graph->findIncoming($reference, $predicate, $after, $limit, $includeRetired)
                : $this->graph->findOutgoing($reference, $predicate, $after, $limit, $includeRetired);
            return ['direction' => $direction, 'endpoint' => ['type' => $reference->endpoint_type, 'key' => $reference->endpoint_key], 'after' => $after, 'limit' => $limit, 'include_retired' => $includeRetired, 'next_cursor' => $result['next_cursor'], 'items' => array_map($this->serialize(...), $result['items'])];
        } catch (\NHK\Core\Graph\Exception\EndpointNotFound) {
            return new \WP_Error('nhk_graph_endpoint_not_found', 'Graph endpoint was not found.', ['status' => 404]);
        } catch (\Throwable $exception) {
            if ($this->isClientError($exception)) return new \WP_Error('nhk_graph_request_invalid', 'Graph request is invalid.', ['status' => 400]);
            return new \WP_Error('nhk_graph_read_failed', 'Graph read failed.', ['status' => 500]);
        }
    }

    private function serialize(GraphEdge $edge): array
    {
        return ['id' => $edge->edge_uuid, 'predicate' => $edge->predicate, 'state' => $edge->state->name, 'revision' => $edge->revision, 'source' => ['type' => $edge->source->reference->endpoint_type, 'key' => $edge->source->reference->endpoint_key], 'target' => ['type' => $edge->target->reference->endpoint_type, 'key' => $edge->target->reference->endpoint_key], 'created_at' => $edge->created_at, 'updated_at' => $edge->updated_at, 'retired_at' => $edge->retired_at];
    }

    private function isClientError(\Throwable $exception): bool
    {
        return $exception instanceof \NHK\Core\Graph\Exception\InvalidEndpointReference
            || $exception instanceof \NHK\Core\Graph\Exception\UnsupportedEndpointType
            || $exception instanceof \NHK\Core\Graph\Exception\UnknownPredicate;
    }
}
