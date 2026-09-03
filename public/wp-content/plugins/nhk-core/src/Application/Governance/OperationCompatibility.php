<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

interface OperationCompatibility
{
    public function supports(string $entityType, string $operation): bool;
}
