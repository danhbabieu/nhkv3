<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Governance\Proposal;

final class CanonicalApplyReadBackVerifier
{
    /** @param callable(string,string):?array $reader */
    public function __construct(private $reader) {}

    /** @return array{entity_type:string,canonical_id:string,active:bool,revision:int,snapshot:array<string,mixed>} */
    public function verify(Proposal $proposal, string $resultId): array
    {
        if ($resultId === '') throw new \RuntimeException('CANONICAL_READBACK_MISSING_RESULT_UUID');
        $readBack = ($this->reader)($proposal->entityType, $resultId);
        if (!is_array($readBack)
            || ($readBack['entity_type'] ?? null) !== $proposal->entityType
            || ($readBack['canonical_id'] ?? null) !== $resultId
            || ($readBack['active'] ?? false) !== true
            || !is_int($readBack['revision'] ?? null)
            || $readBack['revision'] < 1
            || !is_array($readBack['snapshot'] ?? null)) {
            throw new \RuntimeException('CANONICAL_READBACK_VERIFICATION_FAILED');
        }
        return $readBack;
    }
}
