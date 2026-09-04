<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\PublicIdentity;
use NHK\Core\Contracts\PublicIdentity\HistoricPublicRouteResolver;
use NHK\Core\Domain\PublicIdentity\PublicIdentityMutationResult;

final class WpdbHistoricPublicRouteResolver implements HistoricPublicRouteResolver
{
    public function __construct(private object $repository) {}
    public function resolveExact(string $routeType, string $collisionScope, string $path): PublicIdentityMutationResult
    {
        return $this->repository->resolveExact($routeType, $collisionScope, $path);
    }
    public function resolvePath(string $path): PublicIdentityMutationResult { return $this->repository->resolvePath($path); }
    public function resolveHistoric(string $path): array
    {
        if (!method_exists($this->repository, 'resolvePath')) {
            return ['status' => 'UNAVAILABLE_STORAGE'];
        }

        $result = $this->repository->resolvePath($path);
        if (!$result instanceof PublicIdentityMutationResult || !$result->accepted || $result->identity === null || $result->historicRoute === null || $result->identity->currentPath === null) {
            return ['status' => $result instanceof PublicIdentityMutationResult ? ($result->code ?? 'NOT_FOUND') : 'UNAVAILABLE_STORAGE'];
        }

        return [
            'status' => 'FOUND',
            'target' => $result->identity->currentPath,
            'hops' => 1,
        ];
    }
}
