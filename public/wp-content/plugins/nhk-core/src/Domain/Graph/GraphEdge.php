<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
final readonly class GraphEdge {
    public function __construct(public string $edge_uuid, public GraphNode $source, public string $predicate, public GraphNode $target, public EdgeState $state = EdgeState::ACTIVE, public int $revision = 1, public string $created_at = '', public string $updated_at = '', public ?string $retired_at = null) {}
    public function isActive(): bool { return $this->state === EdgeState::ACTIVE; }
}
