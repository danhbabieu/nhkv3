<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

/** Transient exact endpoint reference; it is not a semantic owner or store. */
final readonly class MediaBindingTarget
{
    public function __construct(
        public string $entityType,
        public string $canonicalId,
        public string $stableKey = '',
        public bool $active = true,
    ) {
    }
}
