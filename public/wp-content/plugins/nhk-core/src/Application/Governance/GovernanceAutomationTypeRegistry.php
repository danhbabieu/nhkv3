<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Authority\EntityTypeRegistry;

/** The single registry of owners exposed to Governance Automation policy. */
final class GovernanceAutomationTypeRegistry
{
    /** @return list<string> */
    public static function all(EntityTypeRegistry $types): array
    {
        return array_values(array_unique(array_merge(
            array_map(static fn ($definition): string => $definition->type, $types->all()),
            ['wp_post', 'media', 'video', 'knowledge', 'source', 'evidence', 'relation'],
        )));
    }
}
