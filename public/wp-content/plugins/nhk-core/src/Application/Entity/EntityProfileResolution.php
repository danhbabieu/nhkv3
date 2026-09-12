<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

final readonly class EntityProfileResolution
{
    public const RESOLVED = 'RESOLVED';
    public const COMPATIBILITY_READ = 'COMPATIBILITY_READ';
    public const PROFILE_UNRESOLVED = 'PROFILE_UNRESOLVED';

    public function __construct(
        public string $status,
        public ?string $profileKey,
        public string $familyState,
        public ?string $storedFamily = null,
        public ?string $canonicalFamily = null,
        public ?string $diagnostic = null,
    ) {}

    public function resolved(): bool
    {
        return in_array($this->status, [self::RESOLVED, self::COMPATIBILITY_READ], true);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'profile_key' => $this->profileKey,
            'family_state' => $this->familyState,
            'stored_family' => $this->storedFamily,
            'canonical_family' => $this->canonicalFamily,
            'diagnostic' => $this->diagnostic,
        ];
    }
}
