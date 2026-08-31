<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Graph;

use NHK\Core\Contracts\Graph\AuditSink;
use NHK\Core\Domain\Graph\GraphEdge;
use NHK\Core\Infrastructure\Governance\WpdbAuditSink as EventStore;

final class WpdbAuditSink implements AuditSink
{
    public function __construct(private ?EventStore $events = null) {}
    public function record(string $event, GraphEdge $edge): void
    {
        ($this->events ?? new EventStore())->recordEvent($event, 'graph_edge', $edge->edge_uuid, null, ['revision' => $edge->revision, 'active' => $edge->isActive()]);
    }
}
