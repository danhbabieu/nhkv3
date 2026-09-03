<?php
declare(strict_types=1);

namespace NHK\Core\Domain\PublicIdentity;

final readonly class HistoricPublicRoute
{
    public function __construct(
        public string $identityId,
        public string $routeType,
        public string $collisionScope,
        public string $path,
        public string $oldSlug,
        public int $replacementRevision,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
        if ($identityId === '' || !self::isToken($routeType) || $collisionScope === '' || !self::isPath($path) || !self::isSlug($oldSlug) || $replacementRevision < 1) {
            throw new \InvalidArgumentException('Historic public route is invalid.');
        }
    }

    private static function isToken(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1;
    }

    private static function isSlug(string $value): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1;
    }

    private static function isPath(string $value): bool
    {
        return preg_match('#^/[a-z0-9/-]+/$#', $value) === 1;
    }
}
