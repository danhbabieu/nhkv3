<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

use NHK\Core\Domain\PresentationNavigation\NavigationPlacement;

final class ClockTypeNavigationProjection
{
    public const NAVIGATION_KEY = 'clock_type';

    private bool $available = true;

    public function __construct(private NavigationTreeProjector $projector) {}

    public function available(): bool { return $this->available; }
    public function roots(string $placement = NavigationPlacement::TYPE_INDEX): array { return $this->read(fn (): array => $this->projector->roots(self::NAVIGATION_KEY, $placement)); }
    public function children(string $canonicalUuid, string $placement = NavigationPlacement::TYPE_INDEX): array { return $this->read(fn (): array => $this->projector->children(self::NAVIGATION_KEY, $canonicalUuid, $placement)); }
    public function menu(string $placement): array { return $this->read(fn (): array => $this->projector->menu(self::NAVIGATION_KEY, $placement)); }
    public function breadcrumb(string $canonicalUuid, string $placement = NavigationPlacement::TYPE_INDEX): array { return $this->read(fn (): array => $this->projector->breadcrumb(self::NAVIGATION_KEY, $canonicalUuid, $placement)); }

    /** @param callable():list<array<string,mixed>> $reader @return list<array<string,mixed>> */
    private function read(callable $reader): array
    {
        try { return $reader(); } catch (\Throwable) { $this->available = false; return []; }
    }
}
