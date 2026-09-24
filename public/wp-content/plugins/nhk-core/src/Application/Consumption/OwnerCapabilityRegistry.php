<?php
declare(strict_types=1);

namespace NHK\Core\Application\Consumption;

/** Runtime registry for owner presentation capabilities; it creates no semantic vocabulary. */
final class OwnerCapabilityRegistry
{
    /** @var array<string,OwnerCapability> */
    private array $capabilities = [];

    public function register(OwnerCapability $capability): void
    {
        $this->capabilities[$capability->ownerType] = $capability;
    }

    public function get(string $ownerType): ?OwnerCapability
    {
        return $this->capabilities[strtolower(trim($ownerType))] ?? null;
    }
}
