<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

use NHK\Core\Domain\Governance\Proposal;

interface GovernanceAuditSink
{
    public function record(string $event, Proposal $proposal): void;
    public function recordEvent(string $eventType, string $objectType, string $objectKey, ?int $actorUserId, array $context = []): void;
}
