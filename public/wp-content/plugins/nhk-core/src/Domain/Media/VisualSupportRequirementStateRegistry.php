<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

final class VisualSupportRequirementStateRegistry
{
    public const MISSING = 'MISSING';
    public const RESOLVED = 'RESOLVED';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::MISSING, self::RESOLVED, self::REVIEW_REQUIRED];
    }

    public static function assertKnown(string $state): void
    {
        if (!in_array($state, self::all(), true)) throw new \InvalidArgumentException('Unknown visual support requirement state.');
    }
}
