<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\PublicIdentity;
use NHK\Core\Contracts\PublicIdentity\HistoricPublicRouteResolver;
use NHK\Core\Domain\PublicIdentity\PublicIdentityMutationResult;

final class WpdbHistoricPublicRouteResolver implements HistoricPublicRouteResolver
{
    public function __construct(private WpdbPublicIdentityRepository $repository) {}
    public function resolveExact(string $routeType, string $collisionScope, string $path): PublicIdentityMutationResult
    {
        return $this->repository->resolveExact($routeType, $collisionScope, $path);
    }
    public function resolvePath(string $path): PublicIdentityMutationResult { return $this->repository->resolvePath($path); }
    public function resolveHistoric(string $path): array { return $this->repository->resolveHistoric($path); }
}
