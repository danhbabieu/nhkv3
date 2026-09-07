<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use InvalidArgumentException;
use NHK\Core\Contracts\Governance\AutomationPolicyStorage;
use NHK\Core\Domain\Governance\AutomationMode;

final class GovernanceAutomationPolicyResolver
{
    /** @param list<string> $registeredTypes */
    public function __construct(private array $registeredTypes, private AutomationPolicyStorage $storage) {}

    public function resolve(string $type): AutomationMode
    {
        if (!in_array($type, $this->registeredTypes, true)) throw new InvalidArgumentException('Unknown governance automation type: ' . $type);
        $stored = $this->storage->read();
        return array_key_exists($type, $stored)
            ? AutomationMode::fromStored((string) $stored[$type])
            : AutomationMode::REVIEW_REQUIRED;
    }

    /** @return array<string,AutomationMode> */
    public function all(): array
    {
        $resolved = [];
        foreach ($this->registeredTypes as $type) $resolved[$type] = $this->resolve($type);
        return $resolved;
    }
}
