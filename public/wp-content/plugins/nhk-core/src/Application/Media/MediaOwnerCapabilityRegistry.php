<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

/** Explicit runtime registry for MediaUsage owner capabilities. */
final class MediaOwnerCapabilityRegistry
{
    /** @var array<string,MediaOwnerCapability> */
    private array $capabilities = [];

    public function register(MediaOwnerCapability $capability): void
    {
        $key = $capability->endpointType;
        if (isset($this->capabilities[$key]) && $this->capabilities[$key]->toArray() !== $capability->toArray()) {
            throw new \InvalidArgumentException('MEDIA_OWNER_CAPABILITY_CONFLICT');
        }
        $this->capabilities[$key] = $capability;
    }

    public function forEndpoint(string $endpointType): ?MediaOwnerCapability
    {
        return $this->capabilities[strtolower(trim($endpointType))] ?? null;
    }

    /** @return list<MediaOwnerCapability> */
    public function all(): array
    {
        return array_values($this->capabilities);
    }
}
