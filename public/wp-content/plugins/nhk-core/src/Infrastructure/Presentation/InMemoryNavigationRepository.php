<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Presentation;

use NHK\Core\Contracts\PresentationNavigation\NavigationRepository;
use NHK\Core\Domain\PresentationNavigation\NavigationNode;

final class InMemoryNavigationRepository implements NavigationRepository
{
    /** @var array<string,NavigationNode> */
    private array $nodes = [];

    /** @param list<NavigationNode> $nodes */
    public function __construct(array $nodes = [])
    {
        foreach ($nodes as $node) $this->nodes[$node->id] = $node;
    }

    public function list(string $navigationKey): array
    {
        return array_values(array_filter($this->nodes, static fn (NavigationNode $node): bool => $node->navigationKey === $navigationKey));
    }

    public function save(NavigationNode $node, int $expectedRevision): NavigationNode
    {
        $existing = $this->nodes[$node->id] ?? null;
        if ($existing !== null && $existing->revision !== $expectedRevision) throw new \RuntimeException('NAVIGATION_REVISION_CONFLICT');
        if ($node->id === '') $node = NavigationNode::fromArray([...$node->toArray(), 'id' => $node->canonicalUuid]);
        $this->nodes[$node->id] = $node;
        return $node;
    }

    /** @return array<string,NavigationNode> */
    public function storedNodes(): array { return $this->nodes; }
}
