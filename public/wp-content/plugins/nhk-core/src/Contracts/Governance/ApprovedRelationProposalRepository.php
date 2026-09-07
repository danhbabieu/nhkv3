<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

use NHK\Core\Domain\Governance\Proposal;

interface ApprovedRelationProposalRepository
{
    /** @return list<Proposal> */
    public function findApprovedFingerprintBoundRelations(string $sourceType, string $sourceUuid, string $sourceFingerprint): array;
}
