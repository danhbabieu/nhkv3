<?php
declare(strict_types=1);

namespace NHK\Core\Domain\PublicIdentity;

final readonly class PublicUrlResult
{
    /** @param list<string> $blockers @param list<string> $warnings */
    public function __construct(
        public ?string $finalPath,
        public bool $eligible,
        public array $blockers = [],
        public array $warnings = [],
        public ?int $identityRevision = null,
    ) {
        if ($eligible && ($finalPath === null || !self::isSafePublicPath($finalPath))) {
            throw new \InvalidArgumentException('Eligible public URL path is invalid or exposes internal identity.');
        }
        if (!$eligible && $finalPath !== null && !self::isSafePublicPath($finalPath)) {
            throw new \InvalidArgumentException('Public URL path is invalid.');
        }
        if ($identityRevision !== null && $identityRevision < 1) {
            throw new \InvalidArgumentException('Public identity revision is invalid.');
        }
    }

    private static function isSafePublicPath(string $path): bool
    {
        if (preg_match('#^/[a-z0-9/-]+/$#', $path) !== 1) {
            return false;
        }

        return preg_match('/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/i', $path) !== 1;
    }
}
