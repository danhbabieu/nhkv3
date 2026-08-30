<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\Graph;
use NHK\Core\Domain\Graph\GraphEdge;
interface AuditSink { public function record(string $event, GraphEdge $edge): void; }
