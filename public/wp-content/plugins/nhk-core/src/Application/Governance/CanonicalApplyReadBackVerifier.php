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
        // A relation proposal may be authored from an endpoint domain (for
        // example entity_type=knowledge), but its canonical apply result is
        // owned by Graph. Keep the check strict while resolving the correct
        // canonical owner for the result.
        $canonicalType = in_array($proposal->operation, ['relation_create', 'relation_retire', 'relation_reactivate'], true)
            ? 'relation'
            : $proposal->entityType;
        $readBack = ($this->reader)($canonicalType, $resultId);
        $expectedActive = !in_array($proposal->operation, ['retire', 'relation_retire'], true);
        if (!is_array($readBack)
            || ($readBack['entity_type'] ?? null) !== $canonicalType
            || ($readBack['canonical_id'] ?? null) !== $resultId
            || ($readBack['active'] ?? null) !== $expectedActive
            || !is_int($readBack['revision'] ?? null)
            || $readBack['revision'] < 1
            || !is_array($readBack['snapshot'] ?? null)) {
            throw new \RuntimeException('CANONICAL_READBACK_VERIFICATION_FAILED');
        }
        return $readBack;
    }
}
