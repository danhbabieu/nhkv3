<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Projection;

use InvalidArgumentException;

final readonly class ProjectionRule
{
    /** @param list<string> $predicates @param list<string> $categories @param list<string> $states */
    public function __construct(
        public string $sourceType,
        public string $targetType,
        public array $predicates,
        public string $direction = 'incoming',
        public array $categories = [],
        public int $maxDistance = 1,
        public bool $contextRequired = true,
        public array $states = ['APPROVED'],
    ) {
        if ($sourceType === '' || $targetType === '' || $predicates === [] || !in_array($direction, ['incoming', 'outgoing'], true) || $maxDistance < 1 || $maxDistance > 2) throw new InvalidArgumentException('Projection rule is invalid.');
    }

    public function allows(string $sourceType, string $targetType, string $predicate, string $direction, string $category, int $distance, string $state): bool
    {
        return $this->sourceType === $sourceType && $this->targetType === $targetType && in_array($predicate, $this->predicates, true) && $this->direction === $direction && ($this->categories === [] || in_array($category, $this->categories, true)) && $distance <= $this->maxDistance && in_array($state, $this->states, true);
    }
}
