<?php
declare(strict_types=1);
namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Contracts\PublicIdentity\HistoricPublicRouteResolver;

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
    public function resolve(string $path): array { return $this->resolveHistoric($path); }
}
