<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

use InvalidArgumentException;

final class MediaDetailTypeRegistry
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'WHOLE_FRONT', 'WHOLE_REAR', 'DIAL', 'HANDS', 'GLASS', 'CASE',
            'CASE_INTERIOR', 'MOVEMENT_FRONT', 'MOVEMENT_REAR', 'HAMMER_BANK',
            'ROD_BANK', 'MOVEMENT_LOGO', 'DIAL_LOGO', 'SERIAL', 'MODEL_MARK',
            'STAMP', 'LABEL', 'ENGRAVING', 'COMPONENT_DETAIL',
        ];
    }

    public static function assertKnown(string $detailType): void
    {
        if (!in_array($detailType, self::all(), true)) throw new InvalidArgumentException('Unknown Media detail type: ' . $detailType);
    }
}
