<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Projection;

use InvalidArgumentException;

final readonly class ClaimProjectionCategory
{
    public const VALUES = [
        'identity', 'history', 'classification', 'mechanism', 'configuration',
        'component', 'dial_and_hands', 'case_and_decoration', 'music_and_strike',
        'sound', 'operation', 'dimension', 'material', 'provenance',
        'user_experience', 'identification_rule', 'comparison', 'exception',
        'dispute', 'other',
    ];

    public function __construct(public string $value)
    {
        if (!self::isValid($value)) throw new InvalidArgumentException('Unknown claim projection category.');
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::VALUES, true);
    }
}
