<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Projection;

final class ProjectionStatus
{
    public const CANDIDATE = 'candidate';
    public const VALIDATING = 'validating';
    public const READY = 'ready';
    public const PUBLISHED = 'published';
    public const SUPERSEDED = 'superseded';
    public const FAILED = 'failed';

    public const VALUES = [self::CANDIDATE, self::VALIDATING, self::READY, self::PUBLISHED, self::SUPERSEDED, self::FAILED];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::VALUES, true);
    }
}
