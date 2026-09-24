<?php
declare(strict_types=1);

namespace NHK\Core\Application\Consumption;

/** Capability declaration for an owner-specific EnrichmentPack consumer. */
final readonly class OwnerCapability
{
    /** @param list<string> $surfaces */
    private function __construct(
        public string $ownerType,
        public array $surfaces,
        public bool $canonicalReadback,
        public bool $dependencies,
    ) {
    }

    /** @param list<string> $surfaces */
    public static function forOwner(string $ownerType, array $surfaces, bool $canonicalReadback = false, bool $dependencies = false): self
    {
        $ownerType = strtolower(trim($ownerType));
        if ($ownerType === '' || preg_match('/^[a-z][a-z0-9_:-]{0,63}$/', $ownerType) !== 1) throw new \InvalidArgumentException('OWNER_CAPABILITY_TYPE_INVALID');
        $surfaces = array_values(array_unique(array_filter(array_map(static fn (mixed $surface): string => strtolower(trim((string) $surface)), $surfaces), static fn (string $surface): bool => $surface !== '')));
        if ($surfaces === []) throw new \InvalidArgumentException('OWNER_CAPABILITY_SURFACES_REQUIRED');
        return new self($ownerType, $surfaces, $canonicalReadback, $dependencies);
    }

    public function supports(string $surface): bool
    {
        return in_array(strtolower(trim($surface)), $this->surfaces, true);
    }
}
