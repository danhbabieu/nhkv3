<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\PublicIdentity;

use NHK\Core\Domain\PublicIdentity\PublicIdentity;
use NHK\Core\Domain\PublicIdentity\PublicIdentityMutationResult;

/** Persistence boundary for one current identity per owner and unique route scope/slug. */
interface PublicIdentityRepository
{
    public function findByOwner(string $ownerKind, string $ownerId): ?PublicIdentity;

    public function findByRoute(string $routeType, string $collisionScope, string $slug): ?PublicIdentity;

    public function create(PublicIdentity $identity): PublicIdentityMutationResult;

    public function update(PublicIdentity $identity, int $expectedRevision): PublicIdentityMutationResult;
}
