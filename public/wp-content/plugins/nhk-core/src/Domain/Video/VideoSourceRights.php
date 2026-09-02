<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;

final class VideoSourceRights
{
    public const OWNED = 'OWNED';
    public const AUTHORIZED = 'AUTHORIZED';
    public const PUBLIC_EXTERNAL_REFERENCE = 'PUBLIC_EXTERNAL_REFERENCE';
    public const UNKNOWN = 'UNKNOWN';

    public static function isValid(string $value): bool
    {
        return in_array($value, [self::OWNED, self::AUTHORIZED, self::PUBLIC_EXTERNAL_REFERENCE, self::UNKNOWN], true);
    }
}
