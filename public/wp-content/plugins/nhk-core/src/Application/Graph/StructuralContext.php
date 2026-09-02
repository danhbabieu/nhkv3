<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

final readonly class StructuralContext
{
    /** @param list<string> $relationPath @param list<string> $reasons @param list<string> $warnings */
    public function __construct(public string $entityType, public string $entityId, public ?string $modelId = null, public ?string $brandId = null, public array $relationPath = [], public array $reasons = [], public string $source = 'GRAPH', public bool $canonical = true, public array $warnings = []) {}
}
