<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

use InvalidArgumentException;

final class MediaDiagnosticCodeRegistry
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'ARTICLE_MEDIA_FEATURED_MISSING',
            'ARTICLE_MEDIA_INLINE_MISSING',
            'ARTICLE_MEDIA_SLOTS_SHARE_MEDIA',
            'ARTICLE_MEDIA_PLACEHOLDER',
            'MEDIA_DUPLICATE_CANDIDATE',
            'MEDIA_LOW_RESOLUTION',
            'MEDIA_METADATA_INCOMPLETE',
            'MEDIA_RELATION_UNVERIFIED',
            'MEDIA_RIGHTS_UNVERIFIED',
            'MEDIA_PLACEHOLDER_NOT_PUBLIC',
        ];
    }

    public static function assertKnown(string $code): void
    {
        if (!in_array($code, self::all(), true)) throw new InvalidArgumentException('Unknown Media diagnostic code: ' . $code);
    }
}
