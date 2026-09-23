<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class VideoStatementClassification
{
    public const CANONICAL_SUPPORTED = 'CANONICAL_SUPPORTED';
    public const USER_OBSERVATION = 'USER_OBSERVATION';
    public const SOURCE_SUPPORTED = 'SOURCE_SUPPORTED';
    public const INFERABLE_WITHIN_SCOPE = 'INFERABLE_WITHIN_SCOPE';
    public const UNCERTAIN = 'UNCERTAIN';
    public const CONFLICTING = 'CONFLICTING';
    public const UNSUPPORTED_EXPANSION = 'UNSUPPORTED_EXPANSION';

    public static function all(): array
    {
        return [self::CANONICAL_SUPPORTED, self::USER_OBSERVATION, self::SOURCE_SUPPORTED, self::INFERABLE_WITHIN_SCOPE, self::UNCERTAIN, self::CONFLICTING, self::UNSUPPORTED_EXPANSION];
    }

    public static function assert(string $value): string
    {
        if (!in_array($value, self::all(), true)) throw new \InvalidArgumentException('Video statement classification is invalid.');
        return $value;
    }
}
