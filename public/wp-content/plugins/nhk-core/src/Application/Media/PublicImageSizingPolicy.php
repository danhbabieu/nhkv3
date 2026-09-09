<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

/** Canonical public image sizing: bounded, proportional downscale only. */
final class PublicImageSizingPolicy
{
    public const MAX_LONG_EDGE = 1200;

    /** @return array{width:int,height:int} */
    public static function constrain(int $width, int $height): array
    {
        if ($width < 1 || $height < 1) throw new \InvalidArgumentException('Image dimensions must be positive.');
        $longEdge = max($width, $height);
        if ($longEdge <= self::MAX_LONG_EDGE) return ['width' => $width, 'height' => $height];
        $scale = self::MAX_LONG_EDGE / $longEdge;
        return ['width' => max(1, (int) round($width * $scale)), 'height' => max(1, (int) round($height * $scale))];
    }
}
