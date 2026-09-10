<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/**
 * Marks the synchronous native Post write owned by Editorial Capture.
 *
 * Native WordPress post hooks run inside wp_insert_post/wp_update_post.  A
 * Capture-owned draft must not be treated as an unrelated generic Post while
 * its locked subject/media plan is still being prepared.
 */
final class CaptureEditorialWriteGuard
{
    private static int $depth = 0;

    public static function enter(): void { self::$depth++; }

    public static function leave(): void { self::$depth = max(0, self::$depth - 1); }

    public static function active(): bool { return self::$depth > 0; }
}
