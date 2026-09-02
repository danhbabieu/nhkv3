<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

final readonly class StructuralContext
{
    /** @param list<string> $relationPath @param list<string> $reasons */
    public function __construct(public string $entityType, public string $entityId, public ?string $modelId = null, public ?string $brandId = null, public array $relationPath = [], public array $reasons = []) {}
}
