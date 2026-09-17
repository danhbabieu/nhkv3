<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Authority\EntityTypeRegistry;

/** Canonical policy buckets; UI and persistence consume this registry. */
final class GovernanceAutomationPolicyRegistry
{
    /** @return list<string> */
    public static function keys(array $owners): array
    {
        $keys = array_values(array_unique(array_map('strval', $owners)));
        $targets = array_values(array_unique(array_merge($keys, ['wp_post'])));
        foreach ($targets as $target) foreach (['add', 'replace', 'remove', 'representative_bind'] as $operation) $keys[] = $target . ':media:' . $operation;
        foreach (['media:add', 'media:replace', 'media:remove', 'media:representative_bind'] as $operation) $keys[] = $operation;
        return array_values(array_unique($keys));
    }

    /** @return list<string> */
    public static function authorityTargets(EntityTypeRegistry $types): array
    {
        return array_values(array_map(static fn ($definition): string => $definition->type, $types->all()));
    }
}
