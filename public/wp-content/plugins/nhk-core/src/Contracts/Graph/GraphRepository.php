<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\Graph;
use NHK\Core\Domain\Graph\{GraphEdge,GraphNode,NodeReference,PredicateDefinition};
interface GraphRepository {
    public function resolveNode(NodeReference $reference): GraphNode;
    public function findNode(NodeReference $reference): ?GraphNode;
    public function createEdge(GraphNode $source, PredicateDefinition $predicate, GraphNode $target): GraphEdge;
    public function findEdge(NodeReference $source, string $predicate, NodeReference $target): ?GraphEdge;
    public function findByUuid(string $uuid): ?GraphEdge;
    /** @return array{items:list<GraphEdge>,next_cursor:?int} */ public function outgoing(GraphNode $source, ?string $predicate, int $after_id, int $limit, bool $include_retired): array;
    /** @return array{items:list<GraphEdge>,next_cursor:?int} */ public function incoming(GraphNode $target, ?string $predicate, int $after_id, int $limit, bool $include_retired): array;
    public function retire(GraphEdge $edge, int $expected_revision): GraphEdge;
    public function reactivate(GraphEdge $edge, int $expected_revision): GraphEdge;
    public function nodeHasEdges(GraphNode $node): bool;
    public function deleteNode(GraphNode $node): void;
}
