<?php
declare(strict_types=1);
namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Contracts\PublicIdentity\HistoricPublicRouteResolver;
use NHK\Core\Domain\PublicIdentity\{HistoricPublicRoute, PublicIdentityMutationResult};

final class HistoricPublicRouteService implements HistoricPublicRouteResolver
{
    public function __construct(private object $repository) {}
    public function resolveHistoric(string $path): array
    {
        if ($path === '' || $path[0] !== '/') return ['status' => 'NOT_FOUND'];
        $result = $this->repository->resolveHistoric($path);
        if (($result['status'] ?? '') !== 'FOUND' || (string) ($result['target'] ?? '') === '') return ['status' => (string) ($result['status'] ?? 'NOT_FOUND')];
        return ['status' => 'FOUND', 'target' => (string) $result['target'], 'hops' => 1];
    }
    public function resolveExact(string $routeType, string $collisionScope, string $path): PublicIdentityMutationResult
    {
        $result = $this->resolveHistoric($path);
        if (($result['status'] ?? '') === 'AMBIGUOUS') return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::AMBIGUOUS_HISTORY);
        if (($result['status'] ?? '') !== 'FOUND') return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::UNAVAILABLE_STORAGE);
        return PublicIdentityMutationResult::acceptedHistoricRoute(new HistoricPublicRoute((string)($result['identity_id'] ?? 'history'),$routeType,$collisionScope,$path,(string)($result['old_slug'] ?? trim($path,'/')),1));
    }
    public function resolve(string $path): array { return $this->resolveHistoric($path); }
}
