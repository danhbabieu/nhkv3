<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
final readonly class GraphNode { public function __construct(public int $internal_node_id, public NodeReference $reference) {} }
