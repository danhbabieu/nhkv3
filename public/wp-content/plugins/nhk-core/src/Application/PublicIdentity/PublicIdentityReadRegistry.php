<?php
declare(strict_types=1);

namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;

/** Read-only runtime registry for canonical Public Identity projection. */
final class PublicIdentityReadRegistry
{
    private static ?PublicIdentityRepository $repository = null;

    public static function register(PublicIdentityRepository $repository): void
    {
        self::$repository = $repository;
    }

    public static function repository(): ?PublicIdentityRepository
    {
        return self::$repository;
    }
}
