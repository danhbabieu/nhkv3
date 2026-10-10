<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\FacebookAudit;

use NHK\Core\Application\FacebookAudit\FacebookAuditCheckpoint;
use NHK\Core\Domain\FacebookAudit\FacebookAuditScope;

interface FacebookAuditCheckpointStore
{
    public function load(FacebookAuditScope $scope): ?FacebookAuditCheckpoint;
    public function save(FacebookAuditCheckpoint $checkpoint): void;
}
