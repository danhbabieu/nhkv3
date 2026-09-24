<?php
declare(strict_types=1);

namespace NHK\Core\Application\Consumption;

/** Immutable transient plan between EnrichmentPack and a controlled owner writer. */
final readonly class OwnerConsumptionPlan
{
    private function __construct(
        private OwnerCapability $capability,
        private array $owner,
        private array $surfaces,
        private array $dependencies,
        private array $policies,
        private array $trace,
        private array $coverage,
        private array $gaps,
    ) {
    }

    public static function forCapability(OwnerCapability $capability, array $owner, array $surfaces, array $dependencies = [], array $policies = [], array $trace = [], array $coverage = [], array $gaps = []): self
    {
        $ownerId = trim((string) ($owner['id'] ?? $owner['canonical_id'] ?? ''));
        if ($ownerId === '') throw new \InvalidArgumentException('OWNER_CONSUMPTION_ID_REQUIRED');
        foreach (array_keys($surfaces) as $surface) if (!$capability->supports((string) $surface)) unset($surfaces[$surface]);
        return new self($capability, $owner, $surfaces, $dependencies, $policies, $trace, $coverage, $gaps);
    }

    public function capability(): OwnerCapability { return $this->capability; }
    public function owner(): array { return $this->owner; }
    public function toArray(): array
    {
        return [
            'owner_type' => $this->capability->ownerType,
            'owner' => $this->owner,
            'surfaces' => $this->surfaces,
            'dependencies' => $this->dependencies,
            'policies' => $this->policies,
            'trace' => $this->trace,
            'coverage' => $this->coverage,
            'gaps' => $this->gaps,
            'capabilities' => ['surfaces' => $this->capability->surfaces, 'canonical_readback' => $this->capability->canonicalReadback, 'dependencies' => $this->capability->dependencies],
        ];
    }
}
