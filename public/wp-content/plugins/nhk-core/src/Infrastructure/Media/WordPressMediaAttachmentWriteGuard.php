<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

/** Prevents native attachment hooks from inferring NHK semantic Media. */
final class WordPressMediaAttachmentWriteGuard
{
    private static int $depth = 0;

    public static function enter(): void { self::$depth++; }
    public static function leave(): void { self::$depth = max(0, self::$depth - 1); }
    public static function active(): bool { return self::$depth > 0; }
}
