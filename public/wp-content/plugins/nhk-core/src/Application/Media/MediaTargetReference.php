<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

/** Immutable, resolver-verified endpoint identity used by MediaUsage. */
final readonly class MediaTargetReference
{
    public function __construct(
        public string $endpointType,
        public string $endpointKey,
        public ?string $canonicalUuid,
        public ?string $stableKey,
        public int $revision,
    ) {
        if ($endpointType === '' || $endpointKey === '' || $revision < 1) {
            throw new \InvalidArgumentException('Media target reference is invalid.');
        }
    }
}
