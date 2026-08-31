<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Domain\Governance\Proposal;

final class McpGovernanceHandler
{
    public function __construct(private GovernanceService $governance) {}

    public function create(Proposal $proposal): Proposal { return $this->governance->create($proposal); }
    public function submit(string $id): Proposal { return $this->governance->submit($id); }
    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { return $this->governance->approve($id, $contentFingerprint, $dependencyFingerprint, $actor); }
    public function reject(string $id, string $actor): Proposal { return $this->governance->reject($id, $actor); }
}
