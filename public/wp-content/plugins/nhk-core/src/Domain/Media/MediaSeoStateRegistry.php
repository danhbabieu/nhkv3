<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

use InvalidArgumentException;

final class MediaSeoStateRegistry
{
    public const COMPLETE = 'MEDIA_COMPLETE';
    public const INCOMPLETE_FEATURED = 'MEDIA_INCOMPLETE_FEATURED';
    public const INCOMPLETE_INLINE = 'MEDIA_INCOMPLETE_INLINE';
    public const PLACEHOLDER = 'MEDIA_PLACEHOLDER';
    public const METADATA_INCOMPLETE = 'MEDIA_METADATA_INCOMPLETE';
    public const LOW_RESOLUTION = 'MEDIA_LOW_RESOLUTION';
    public const RELATION_UNVERIFIED = 'MEDIA_RELATION_UNVERIFIED';
    public const RIGHTS_UNVERIFIED = 'MEDIA_RIGHTS_UNVERIFIED';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::COMPLETE,
            self::INCOMPLETE_FEATURED,
            self::INCOMPLETE_INLINE,
            self::PLACEHOLDER,
            self::METADATA_INCOMPLETE,
            self::LOW_RESOLUTION,
            self::RELATION_UNVERIFIED,
            self::RIGHTS_UNVERIFIED,
        ];
    }

    public static function assertKnown(string $state): void
    {
        if (!in_array($state, self::all(), true)) throw new InvalidArgumentException('Unknown Media SEO state: ' . $state);
    }
}
