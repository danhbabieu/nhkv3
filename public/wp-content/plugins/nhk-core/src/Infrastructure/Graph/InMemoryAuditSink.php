<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Graph;
use NHK\Core\Contracts\Graph\AuditSink;
use NHK\Core\Domain\Graph\GraphEdge;
final class InMemoryAuditSink implements AuditSink { public array $events=[]; public function record(string $event, GraphEdge $edge): void { $this->events[]=['event'=>$event,'edge_uuid'=>$edge->edge_uuid]; } }
