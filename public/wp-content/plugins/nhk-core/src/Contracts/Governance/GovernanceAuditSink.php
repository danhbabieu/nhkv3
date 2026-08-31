<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

use NHK\Core\Domain\Governance\Proposal;

interface GovernanceAuditSink
{
    public function record(string $event, Proposal $proposal): void;
}
