<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Dictionary;

final class DictionaryCandidateState
{
    public const DETECTED = 'DETECTED';
    public const RESOLVED_EXISTING = 'RESOLVED_EXISTING';
    public const NEEDS_REVIEW = 'NEEDS_REVIEW';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const PROPOSED_NEW = 'PROPOSED_NEW';
    public const APPROVED = 'APPROVED';
    public const REJECTED = 'REJECTED';
    public const IGNORED = 'IGNORED';
    public const DO_NOT_SUGGEST = 'DO_NOT_SUGGEST';

    public static function all(): array
    {
        return [self::DETECTED, self::RESOLVED_EXISTING, self::NEEDS_REVIEW, self::AMBIGUOUS, self::PROPOSED_NEW, self::APPROVED, self::REJECTED, self::IGNORED, self::DO_NOT_SUGGEST];
    }

    public static function valid(string $state): bool
    {
        return in_array($state, self::all(), true);
    }

    public static function suppressed(string $state): bool
    {
        return $state === self::DO_NOT_SUGGEST;
    }
}
