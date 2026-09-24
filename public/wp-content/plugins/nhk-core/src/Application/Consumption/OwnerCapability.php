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
        public array $identityRequirements,
        public array $canonicalCompletion,
        public array $minimumSafeRepresentation,
        public array $publicationRequirements,
        public string $readbackStrategy,
        public string $dependencyPolicy,
    ) {
    }

    /** @param list<string> $surfaces */
    public static function forOwner(string $ownerType, array $surfaces, bool $canonicalReadback = false, bool $dependencies = false, array $policy = []): self
    {
        $ownerType = strtolower(trim($ownerType));
        if ($ownerType === '' || preg_match('/^[a-z][a-z0-9_:-]{0,63}$/', $ownerType) !== 1) throw new \InvalidArgumentException('OWNER_CAPABILITY_TYPE_INVALID');
        $surfaces = array_values(array_unique(array_filter(array_map(static fn (mixed $surface): string => strtolower(trim((string) $surface)), $surfaces), static fn (string $surface): bool => $surface !== '')));
        if ($surfaces === []) throw new \InvalidArgumentException('OWNER_CAPABILITY_SURFACES_REQUIRED');
        return new self(
            $ownerType,
            $surfaces,
            $canonicalReadback,
            $dependencies,
            self::records($policy['identity_requirements'] ?? []),
            self::records($policy['canonical_completion'] ?? []),
            self::records($policy['minimum_safe_representation'] ?? []),
            self::records($policy['publication_requirements'] ?? []),
            self::policyString($policy['readback_strategy'] ?? ($canonicalReadback ? 'canonical_id' : 'none'), 'none'),
            self::policyString($policy['dependency_policy'] ?? ($dependencies ? 'consumed_only' : 'none'), 'none'),
        );
    }

    public function supports(string $surface): bool
    {
        return in_array(strtolower(trim($surface)), $this->surfaces, true);
    }

    /** @return array<string,mixed> */
    private static function records(mixed $value): array
    {
        if (!is_array($value)) return [];
        $result = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) $result[(string) $key] = $item;
            elseif (is_scalar($item)) $result[(string) $key] = $item;
        }
        return $result;
    }

    private static function policyString(mixed $value, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        return $value !== '' && preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $value) === 1 ? $value : $fallback;
    }
}
