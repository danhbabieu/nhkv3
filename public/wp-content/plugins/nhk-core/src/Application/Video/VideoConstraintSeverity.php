<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class VideoConstraintSeverity
{
    public const INFO = 'INFO';
    public const REPAIRABLE = 'REPAIRABLE';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    public const HARD_BLOCK = 'HARD_BLOCK';

    public static function all(): array { return [self::INFO, self::REPAIRABLE, self::REVIEW_REQUIRED, self::HARD_BLOCK]; }
    public static function assert(string $value): string
    {
        if (!in_array($value, self::all(), true)) throw new \InvalidArgumentException('Video constraint severity is invalid.');
        return $value;
    }
}
