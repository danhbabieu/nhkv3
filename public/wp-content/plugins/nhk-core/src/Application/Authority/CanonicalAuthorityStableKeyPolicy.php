<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

use NHK\Core\Application\PublicIdentity\CanonicalPublicSlugPolicy;

/** Server-owned stable-key policy; callers may preview but never override it. */
final class CanonicalAuthorityStableKeyPolicy
{
    public const VERSION = '1.0.0';

    /** @param array<string,mixed> $options */
    public function preview(string $entityType, string $name, array $options = []): string
    {
        $allowed = ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'];
        if (!in_array($entityType, $allowed, true)) throw new \InvalidArgumentException('UNSUPPORTED_ENTITY_TYPE');
        $slug = CanonicalPublicSlugPolicy::normalize($name);
        if ($slug === '') throw new \InvalidArgumentException('AUTHORITY_STABLE_KEY_NAME_INVALID');
        if ($entityType !== 'classification') return 'nhk:' . $entityType . ':' . $slug;
        $family = CanonicalPublicSlugPolicy::normalize((string) ($options['family'] ?? ''));
        if ($family === '') throw new \InvalidArgumentException('CLASSIFICATION_FAMILY_REQUIRED');
        return 'nhk:classification:' . $family . '.' . $slug;
    }
}
