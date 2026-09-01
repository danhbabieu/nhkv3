<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
use InvalidArgumentException;
final readonly class GraphEdge {
    public function __construct(public string $edge_uuid, public GraphNode $source, public string $predicate, public GraphNode $target, public EdgeState $state = EdgeState::ACTIVE, public int $revision = 1, public string $created_at = '', public string $updated_at = '', public ?string $retired_at = null) {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $edge_uuid)) throw new InvalidArgumentException('Graph edge UUID is invalid.');
        if ($revision < 1 || trim($predicate) === '') throw new InvalidArgumentException('Graph edge revision or predicate is invalid.');
    }
    public function isActive(): bool { return $this->state === EdgeState::ACTIVE; }
}
