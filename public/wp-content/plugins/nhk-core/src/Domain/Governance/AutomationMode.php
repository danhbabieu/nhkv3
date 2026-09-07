<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Governance;

use InvalidArgumentException;

enum AutomationMode: string
{
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    case AUTO_APPROVE = 'AUTO_APPROVE';
    case AUTO_PUBLISH = 'AUTO_PUBLISH';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }

    public static function fromStored(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidArgumentException('Invalid governance automation mode: ' . $value);
    }
}
