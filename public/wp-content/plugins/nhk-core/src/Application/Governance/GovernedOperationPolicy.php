<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

/** Immutable generic metadata for one registered Governance operation. */
final readonly class GovernedOperationPolicy
{
    /** @param list<string> $requiredCapabilities */
    public function __construct(
        public string $entityType,
        public string $operation,
        public string $operationFamily,
        public string $lifecycleClass,
        public string $revisionPolicy,
        public string $targetBinding,
        public bool $captureStagingAllowed,
        public bool $productionAllowed,
        public array $requiredCapabilities = [],
    ) {}
}
