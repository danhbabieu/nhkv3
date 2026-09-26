<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\PresentationNavigation;

use NHK\Core\Domain\PresentationNavigation\NavigationNode;

interface NavigationRepository
{
    /** @return list<NavigationNode> */
    public function list(string $navigationKey): array;

    public function save(NavigationNode $node, int $expectedRevision): NavigationNode;
}
