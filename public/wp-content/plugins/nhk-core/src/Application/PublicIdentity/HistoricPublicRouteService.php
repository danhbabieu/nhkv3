<?php
declare(strict_types=1);
namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Contracts\PublicIdentity\HistoricPublicRouteResolver;

final class HistoricPublicRouteService implements HistoricPublicRouteResolver
{
    /** @param \Closure(string,string,string):(?string)|null $currentRoute */
    public function __construct(private object $repository, private ?\Closure $currentRoute = null) {}

    public function resolveHistoric(string $path): array
    {
        if ($path === '' || $path[0] !== '/') return ['status' => 'NOT_FOUND'];
        $result = $this->repository->resolveHistoric($path);
        if (($result['status'] ?? '') !== 'FOUND') return ['status' => (string) ($result['status'] ?? 'NOT_FOUND')];

        $target = trim((string) ($result['target'] ?? ''));
        if ($this->currentRoute !== null) {
            $ownerKind = trim((string) ($result['owner_kind'] ?? ''));
            $ownerId = trim((string) ($result['owner_id'] ?? ''));
            $routeType = trim((string) ($result['route_type'] ?? ''));
            if ($ownerKind === '' || $ownerId === '' || $routeType === '') return ['status' => 'INELIGIBLE'];
            $resolved = ($this->currentRoute)($ownerKind, $ownerId, $routeType);
            if (!is_string($resolved) || trim($resolved) === '') return ['status' => 'INELIGIBLE'];
            $target = trim($resolved);
        }

        if ($target === '' || $target[0] !== '/') return ['status' => 'INELIGIBLE'];
        return ['status' => 'FOUND', 'target' => $target, 'hops' => 1];
    }

    public function resolve(string $path): array { return $this->resolveHistoric($path); }
}
