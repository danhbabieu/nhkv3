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

    /**
     * Resolve the most specific policy for a governed operation.  The node is
     * the source of truth for target identity; callers cannot select an
     * arbitrary policy bucket independently of the proposal payload.
     *
     * Precedence is exact target-operation, owner-operation, then owner.  The
     * legacy owner-only API remains intact for existing callers.
     * @param array<string,mixed> $node
     */
    public function resolveForNode(array $node): AutomationMode
    {
        $owner = strtolower(trim((string) ($node['entity_type'] ?? '')));
        if (!in_array($owner, $this->registeredTypes, true)) throw new InvalidArgumentException('Unknown governance automation type: ' . $owner);
        $operation = strtolower(trim((string) ($node['operation'] ?? '')));
        $target = is_array($node['target'] ?? null) ? $node['target'] : [];
        $targetType = strtolower(trim((string) ($target['type'] ?? $node['target_type'] ?? $owner)));
        $stored = $this->storage->read();
        foreach (array_values(array_unique(array_filter([
            $targetType !== '' && $owner !== '' && $operation !== '' ? $targetType . ':' . $owner . ':' . $operation : '',
            $owner !== '' && $operation !== '' ? $owner . ':' . $operation : '',
            $owner,
        ]))) as $key) {
            if (array_key_exists($key, $stored)) return AutomationMode::fromStored((string) $stored[$key]);
        }
        return AutomationMode::REVIEW_REQUIRED;
    }

    /** @return array<string,AutomationMode> */
    public function all(): array
    {
        $resolved = [];
        foreach ($this->registeredTypes as $type) $resolved[$type] = $this->resolve($type);
        return $resolved;
    }
}
