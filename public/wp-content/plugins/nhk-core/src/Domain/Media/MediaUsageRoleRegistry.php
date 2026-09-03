<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

use InvalidArgumentException;

final class MediaUsageRoleRegistry
{
    public const FEATURED_PRIMARY = 'featured_primary';
    public const INLINE_PRIMARY = 'inline_primary';
    public const INLINE_SUPPORTING = 'inline_supporting';
    public const REPRESENTATIVE = 'representative';
    public const EVIDENCE = 'evidence';
    public const TECHNICAL_DETAIL = 'technical_detail';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::FEATURED_PRIMARY,
            self::INLINE_PRIMARY,
            self::INLINE_SUPPORTING,
            self::REPRESENTATIVE,
            self::EVIDENCE,
            self::TECHNICAL_DETAIL,
            'featured',
            'inline',
            'gallery',
            'thumbnail',
            'source',
        ];
    }

    /** @return list<string> */
    public static function mandatoryArticleRoles(): array
    {
        return [self::FEATURED_PRIMARY, self::INLINE_PRIMARY];
    }

    public static function assertKnown(string $role): void
    {
        if (!in_array($role, self::all(), true)) throw new InvalidArgumentException('Unknown MediaUsage role: ' . $role);
    }
}
