<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

final readonly class Media
{
    public function __construct(
        public string $canonicalId,
        public string $stableKey,
        public string $canonicalName,
        public string $readiness = 'draft',
        public array $provenance = [],
        public bool $active = true,
        public int $revision = 1,
    ) {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $canonicalId)) throw new InvalidMedia('Media canonical UUID is invalid.');
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/', $stableKey) || $canonicalName === '') throw new InvalidMedia('Media identity is invalid.');
        if (!in_array($readiness, ['draft', 'ready', 'blocked'], true)) throw new InvalidMedia('Media readiness is invalid.');
        if ($revision < 1) throw new InvalidMedia('Media revision must be positive.');
    }
}
