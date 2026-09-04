<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\PublicIdentity;
use NHK\Core\Contracts\PublicIdentity\HistoricPublicRouteResolver;

final class WpdbHistoricPublicRouteResolver implements HistoricPublicRouteResolver
{
    public function __construct(private WpdbPublicIdentityRepository $repository) {}
    public function resolveHistoric(string $path): array { return $this->repository->resolveHistoric($path); }
}
