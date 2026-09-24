<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\MediaUsageRoleRegistry;

/** Runtime contract for MediaUsage owners and their required readback gates. */
final readonly class MediaOwnerCapability
{
    /** @param list<string> $roles @param list<string> $surfaces */
    private function __construct(
        public string $endpointType,
        public array $roles,
        public bool $requiresProjection,
        public bool $requiresPublicSurface,
        public bool $requiresFrontendReadback,
        public string $representativeSlot,
        public int $maxActiveRepresentatives,
        public array $surfaces,
    ) {
    }

    /** @param list<string> $roles @param list<string> $surfaces */
    public static function forEndpoint(
        string $endpointType,
        array $roles,
        bool $requiresProjection = false,
        bool $requiresPublicSurface = false,
        bool $requiresFrontendReadback = false,
        string $representativeSlot = 'representative',
        int $maxActiveRepresentatives = 1,
        array $surfaces = [],
    ): self {
        $endpointType = strtolower(trim($endpointType));
        if ($endpointType === '' || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $endpointType) !== 1) {
            throw new \InvalidArgumentException('MEDIA_OWNER_CAPABILITY_ENDPOINT_INVALID');
        }
        if ($maxActiveRepresentatives < 0) throw new \InvalidArgumentException('MEDIA_OWNER_CAPABILITY_CARDINALITY_INVALID');
        $roles = array_values(array_unique(array_filter(array_map(static fn (mixed $role): string => trim((string) $role), $roles))));
        if ($roles === []) throw new \InvalidArgumentException('MEDIA_OWNER_CAPABILITY_ROLES_REQUIRED');
        foreach ($roles as $role) MediaUsageRoleRegistry::assertKnown($role);
        $representativeSlot = trim($representativeSlot);
        if ($representativeSlot === '' || preg_match('/^[a-z][a-z0-9_:-]{0,31}$/', $representativeSlot) !== 1) {
            throw new \InvalidArgumentException('MEDIA_OWNER_CAPABILITY_SLOT_INVALID');
        }
        $surfaces = array_values(array_unique(array_filter(array_map(static fn (mixed $surface): string => strtolower(trim((string) $surface),), $surfaces))));
        return new self($endpointType, $roles, $requiresProjection, $requiresPublicSurface, $requiresFrontendReadback, $representativeSlot, $maxActiveRepresentatives, $surfaces);
    }

    public function supportsRole(string $role): bool
    {
        return in_array(trim($role), $this->roles, true);
    }

    public function toArray(): array
    {
        return [
            'endpoint_type' => $this->endpointType,
            'roles' => $this->roles,
            'requires_projection' => $this->requiresProjection,
            'requires_public_surface' => $this->requiresPublicSurface,
            'requires_frontend_readback' => $this->requiresFrontendReadback,
            'representative_slot' => $this->representativeSlot,
            'max_active_representatives' => $this->maxActiveRepresentatives,
            'surfaces' => $this->surfaces,
        ];
    }
}
