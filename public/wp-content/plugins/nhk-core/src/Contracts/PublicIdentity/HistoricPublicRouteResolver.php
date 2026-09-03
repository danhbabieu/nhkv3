<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\PublicIdentity;

use NHK\Core\Domain\PublicIdentity\PublicIdentityMutationResult;

/** Exact historic-route lookup boundary; ambiguous or unavailable lookup returns a typed failure result. */
interface HistoricPublicRouteResolver
{
    public function resolveExact(string $routeType, string $collisionScope, string $path): PublicIdentityMutationResult;
}
