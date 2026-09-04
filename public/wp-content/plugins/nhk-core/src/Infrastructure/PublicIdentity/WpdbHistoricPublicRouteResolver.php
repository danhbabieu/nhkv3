<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\PublicIdentity;
use NHK\Core\Contracts\PublicIdentity\HistoricPublicRouteResolver;
use NHK\Core\Domain\PublicIdentity\{HistoricPublicRoute, PublicIdentityMutationResult};

final class WpdbHistoricPublicRouteResolver implements HistoricPublicRouteResolver
{
    public function __construct(private WpdbPublicIdentityRepository $repository) {}
    public function resolveExact(string $routeType, string $collisionScope, string $path): PublicIdentityMutationResult
    {
        $result = $this->repository->resolveHistoric($path);
        if (($result['status'] ?? '') === 'AMBIGUOUS') return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::AMBIGUOUS_HISTORY);
        if (($result['status'] ?? '') !== 'FOUND') return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::UNAVAILABLE_STORAGE);
        return PublicIdentityMutationResult::acceptedHistoricRoute(new HistoricPublicRoute((string)($result['identity_id'] ?? ''),$routeType,$collisionScope,$path,(string)($result['old_slug'] ?? ''),1));
    }
    public function resolveHistoric(string $path): array { return $this->repository->resolveHistoric($path); }
}
