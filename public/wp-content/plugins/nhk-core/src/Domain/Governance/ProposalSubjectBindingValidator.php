<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Governance;

use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Governance\Exception\ProposalSubjectBindingInvalid;

/**
 * Keeps UUID-bound Proposal subjects distinct from the entity type label.
 *
 * Some creation commands use a logical subject until a canonical identity is
 * allocated. Video commands and component identity-changing commands do not:
 * their subject is already known and must be bound before persistence.
 */
final class ProposalSubjectBindingValidator
{
    public static function assertValid(Proposal $proposal): void
    {
        if (self::isValid($proposal)) return;
        throw new ProposalSubjectBindingInvalid('PROPOSAL_SUBJECT_BINDING_INVALID');
    }

    public static function isValid(Proposal $proposal): bool
    {
        $type = strtolower(trim($proposal->entityType));
        $operation = strtolower(trim($proposal->operation));
        if ($type === 'video') {
            if (!UuidCodec::isValid($proposal->subjectId)) return false;
            if ($operation === 'ingest') {
                $canonicalId = trim((string) ($proposal->payload['canonical_id'] ?? ''));
                return UuidCodec::isValid($canonicalId) && self::sameUuid($canonicalId, $proposal->subjectId);
            }
            return true;
        }
        if ($type === 'component' && in_array($operation, ['merge', 'rekey', 'update', 'rename', 'retire', 'reactivate'], true)) {
            if (!UuidCodec::isValid($proposal->subjectId)) return false;
            if ($operation === 'merge' && array_key_exists('source_uuid', $proposal->payload)) {
                return UuidCodec::isValid((string) $proposal->payload['source_uuid'])
                    && self::sameUuid((string) $proposal->payload['source_uuid'], $proposal->subjectId);
            }
        }
        return true;
    }

    private static function sameUuid(string $left, string $right): bool
    {
        return strtolower($left) === strtolower($right);
    }
}
