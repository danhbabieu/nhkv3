<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Domain\Graph\{NodeReference, PredicateDefinition, PredicateRegistry};

/** Read-only policy for walking stored edge direction; it never invents an inverse predicate. */
final class PredicateTraversalPolicy
{
    public function __construct(private PredicateRegistry $predicates) {}

    public function permits(NodeReference $current, string $direction, NodeReference $other, string $predicate): bool
    {
        try { $definition = $this->predicates->get($predicate); } catch (\Throwable) { return false; }
        if (!$definition->active || !in_array($direction, ['outgoing', 'incoming'], true)) return false;
        return $direction === 'outgoing'
            ? $definition->allows($current->endpoint_type, $other->endpoint_type)
            : $definition->allows($other->endpoint_type, $current->endpoint_type);
    }

    public function definition(string $predicate): ?PredicateDefinition
    {
        try { return $this->predicates->get($predicate); } catch (\Throwable) { return null; }
    }
}
