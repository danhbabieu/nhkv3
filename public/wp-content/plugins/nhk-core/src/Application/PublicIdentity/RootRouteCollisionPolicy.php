<?php
declare(strict_types=1);

namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Contracts\PublicIdentity\RootRouteOwnershipReader;

/**
 * Read-only global root collision gate. It never allocates, renames or
 * normalizes a Public Identity.
 */
final class RootRouteCollisionPolicy
{
    public function __construct(private RootRouteOwnershipReader $ownership) {}

    /** @return array<string,mixed> */
    public function evaluate(string $path, ?string $excludeIdentityId = null): array
    {
        $path = trim($path);
        if (!preg_match('#^/[a-z0-9][a-z0-9-]*/$#', $path)) {
            return ['status' => 'BLOCKED', 'reason' => 'ROOT_PATH_INVALID', 'path' => $path, 'owners' => []];
        }

        $inspection = $this->ownership->inspect($path);
        if (($inspection['status'] ?? '') !== 'AVAILABLE') {
            return [
                'status' => 'UNAVAILABLE_IMPLEMENTATION_GAP',
                'reason' => 'ROOT_ROUTE_REGISTRY_UNAVAILABLE',
                'path' => $path,
                'owners' => [],
                'source' => (string) ($inspection['source'] ?? 'unknown'),
            ];
        }

        $owners = [];
        foreach ((array) ($inspection['owners'] ?? []) as $owner) {
            if (!is_array($owner)) continue;
            if ($excludeIdentityId !== null && $excludeIdentityId !== '' && (string) ($owner['identity_id'] ?? '') === $excludeIdentityId) continue;
            $owners[] = $owner;
        }
        if ($owners !== []) {
            return [
                'status' => 'BLOCKED',
                'reason' => 'PUBLIC_SLUG_CONFLICT',
                'path' => $path,
                'owners' => $owners,
                'source' => (string) ($inspection['source'] ?? 'unknown'),
            ];
        }

        return ['status' => 'AVAILABLE', 'path' => $path, 'owners' => [], 'source' => (string) ($inspection['source'] ?? 'unknown')];
    }
}
