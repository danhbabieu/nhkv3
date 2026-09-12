<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Authority\AuthorityEntity;

/** A transient, reviewable candidate; never a Graph or Authority command. */
final readonly class ClockTypeShadowCandidate
{
    public function __construct(
        public string $classificationUuid,
        public string $stableKey,
        public string $name,
        public string $family,
        public string $canonicalFamily,
        public string $resolutionBasis,
        public string $reviewClass,
        public string $origin,
        public string $profileStatus,
        public int $revision,
        /** @var list<string> */
        public array $diagnostics = [],
    ) {}

    public static function fromEntity(
        AuthorityEntity $entity,
        string $basis,
        string $reviewClass,
        string $origin,
        string $profileStatus,
        array $diagnostics = [],
    ): self {
        return new self(
            $entity->canonicalId,
            $entity->stableKey,
            $entity->canonicalName,
            (string) ($entity->payload['family'] ?? ''),
            'clock_type',
            $basis,
            $reviewClass,
            $origin,
            $profileStatus,
            $entity->revision,
            array_values(array_unique(array_map('strval', $diagnostics))),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'classification_uuid' => $this->classificationUuid,
            'stable_key' => $this->stableKey,
            'name' => $this->name,
            'family' => $this->family,
            'canonical_family' => $this->canonicalFamily,
            'resolution_basis' => $this->resolutionBasis,
            'review_class' => $this->reviewClass,
            'origin' => $this->origin,
            'profile_status' => $this->profileStatus,
            'revision' => $this->revision,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
