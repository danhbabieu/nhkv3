<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

/**
 * Applies WordPress' EXIF orientation transform to an already loaded editor.
 *
 * Orientation must be normalized in the editor before its effective size is
 * used for any resize or derivative save.
 */
final class WordPressImageOrientationNormalizer
{
    public function normalize(object $editor): void
    {
        if (!method_exists($editor, 'maybe_exif_rotate')) {
            throw new \RuntimeException('WORDPRESS_MEDIA_AUTO_ORIENT_UNAVAILABLE');
        }

        $rotated = $editor->maybe_exif_rotate();
        if (function_exists('is_wp_error') && is_wp_error($rotated)) {
            throw new \RuntimeException('WORDPRESS_MEDIA_AUTO_ORIENT_FAILED');
        }
    }
}
