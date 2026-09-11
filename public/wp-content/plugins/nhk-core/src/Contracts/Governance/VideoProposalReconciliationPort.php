<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

interface VideoProposalReconciliationPort
{
    /** @return array<string,mixed> */
    public function reconcile(string $proposalId): array;
}
