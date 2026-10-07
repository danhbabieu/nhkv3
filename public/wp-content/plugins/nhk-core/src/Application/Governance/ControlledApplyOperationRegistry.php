<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

/** Compatibility adapter backed by the canonical read-only operation policy. */
final class ControlledApplyOperationRegistry implements OperationCompatibility
{
    public function __construct(private GovernedOperationPolicyRegistry $policy = new GovernedOperationPolicyRegistry()) {}

    public function supports(string $entityType, string $operation): bool
    {
        return $this->policy->supports($entityType, $operation);
    }
}
