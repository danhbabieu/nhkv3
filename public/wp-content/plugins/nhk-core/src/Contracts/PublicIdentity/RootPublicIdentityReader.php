<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\PublicIdentity;

/**
 * Read-only lookup for identities that already own a root-level path.
 *
 * This deliberately sits beside PublicIdentityRepository: the existing
 * repository contract is owner-oriented and must not gain a slug resolver
 * that could be mistaken for an allocation API.
 */
interface RootPublicIdentityReader
{
    /** @return list<array<string,mixed>> */
    public function findCurrentByRootSlug(string $slug): array;
}
