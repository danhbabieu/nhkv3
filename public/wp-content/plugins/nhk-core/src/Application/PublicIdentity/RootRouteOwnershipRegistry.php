<?php
declare(strict_types=1);

namespace NHK\Core\Application\PublicIdentity;

/**
 * In-memory read-side registry for sanctioned root route owners. It records
 * ownership metadata supplied by existing route owners; it never persists,
 * allocates or changes a public identity.
 */
final class RootRouteOwnershipRegistry
{
    /** @var array<string,list<array<string,mixed>>> */
    private array $owners = [];

    /** @param array<string,mixed> $owner */
    public function register(string $path, array $owner): void
    {
        $path = $this->normalizePath($path);
        if ($owner === []) throw new \InvalidArgumentException('ROOT_ROUTE_OWNER_INVALID');

        $existing = $this->owners[$path] ?? [];
        foreach ($existing as $candidate) {
            if ($candidate === $owner) return;
            throw new \RuntimeException('PUBLIC_SLUG_CONFLICT');
        }
        $this->owners[$path][] = $owner;
    }

    /** @return array{status:string,owners:list<array<string,mixed>>,source:string} */
    public function inspect(string $path): array
    {
        $path = $this->normalizePath($path);
        if (!array_key_exists($path, $this->owners)) {
            return ['status' => 'UNAVAILABLE', 'owners' => [], 'source' => 'global-route-registry'];
        }
        return ['status' => 'AVAILABLE', 'owners' => $this->owners[$path], 'source' => 'registered-route-owner'];
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/') . '/';
        if (!preg_match('#^/[a-z0-9][a-z0-9-]*/$#', $path)) throw new \InvalidArgumentException('ROOT_PATH_INVALID');
        return $path;
    }
}
