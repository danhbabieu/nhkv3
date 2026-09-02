<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

final readonly class StructuralParentCompatibility
{
    /** @param list<string> $warnings */
    public function __construct(
        public string $childType,
        public string $childId,
        public string $parentType,
        public ?string $parentId,
        public string $classification,
        public string $source = 'COMPATIBILITY_PAYLOAD',
        public bool $canonical = false,
        public array $warnings = ['DATA_COMPATIBILITY_GAP'],
    ) {}

    public function safe(): bool
    {
        return $this->classification === 'SAFE_UNIQUE_COMPATIBILITY_PARENT' && $this->parentId !== null;
    }
}
