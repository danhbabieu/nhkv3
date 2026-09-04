<?php
declare(strict_types=1);
namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Contracts\PublicIdentity\HistoricPublicRouteResolver;
use NHK\Core\Domain\PublicIdentity\PublicIdentityMutationResult;

final class HistoricPublicRouteService implements HistoricPublicRouteResolver
{
    public function __construct(private object $repository, private ?\Closure $eligible = null) {}
    public function resolveHistoric(string $path): array
    {
        if ($path === '' || $path[0] !== '/') return ['status' => 'NOT_FOUND'];
        $result = method_exists($this->repository, 'resolvePath') ? $this->repository->resolvePath($path) : (method_exists($this->repository, 'resolveHistoric') ? $this->repository->resolveHistoric($path) : null);
        if ($result instanceof PublicIdentityMutationResult) {
            if (!$result->accepted || $result->identity === null || $result->historicRoute === null || $result->identity->currentPath === null) return ['status' => $result->code ?? 'NOT_FOUND'];
            return ['status' => 'FOUND', 'target' => $result->identity->currentPath, 'hops' => 1];
        }
        if (!is_array($result) || ($result['status'] ?? '') !== 'FOUND' || (string) ($result['target'] ?? '') === '') return ['status' => is_array($result) ? (string) ($result['status'] ?? 'NOT_FOUND') : 'UNAVAILABLE_STORAGE'];
        return ['status' => 'FOUND', 'target' => (string) $result['target'], 'hops' => 1];
    }
    public function resolveExact(string $routeType, string $collisionScope, string $path): PublicIdentityMutationResult
    {
        if ($this->eligible === null) return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        if (!preg_match('#^/[a-z0-9/-]+/$#i', $path) || $routeType === '' || $collisionScope === '') return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::UNKNOWN_ROUTE);
        if (!method_exists($this->repository, 'resolveExact')) return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::UNAVAILABLE_STORAGE);
        $result = $this->repository->resolveExact($routeType, $collisionScope, $path);
        if (!$result instanceof PublicIdentityMutationResult || !$result->accepted) return $result instanceof PublicIdentityMutationResult ? $result : PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::UNAVAILABLE_STORAGE);
        if ($result->identity !== null && $this->eligible !== null && !($this->eligible)($result->identity)) return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        return $result;
    }
    public function resolvePath(string $path): PublicIdentityMutationResult
    {
        if ($this->eligible === null || !method_exists($this->repository, 'resolvePath')) return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        $result = $this->repository->resolvePath($path);
        if (!$result instanceof PublicIdentityMutationResult || !$result->accepted) return $result instanceof PublicIdentityMutationResult ? $result : PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::UNAVAILABLE_STORAGE);
        return ($result->identity !== null && ($this->eligible)($result->identity)) ? $result : PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
    }
    public function resolve(string $path): array { return $this->resolveHistoric($path); }
}
