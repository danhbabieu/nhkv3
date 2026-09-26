<?php
declare(strict_types=1);

namespace NHK\Core\Domain\PresentationNavigation;

final class NavigationPlacement
{
    public const TYPE_INDEX = 'type_index';
    public const HEADER_MENU = 'header_menu';
    public const MOBILE_MENU = 'mobile_menu';
    public const SIDEBAR = 'sidebar';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::TYPE_INDEX, self::HEADER_MENU, self::MOBILE_MENU, self::SIDEBAR];
    }

    public static function field(string $placement): string
    {
        return match ($placement) {
            self::TYPE_INDEX => 'show_in_type_index',
            self::HEADER_MENU => 'show_in_header_menu',
            self::MOBILE_MENU => 'show_in_mobile_menu',
            self::SIDEBAR => 'show_in_sidebar',
            default => throw new \InvalidArgumentException('UNKNOWN_NAVIGATION_PLACEMENT'),
        };
    }
}
