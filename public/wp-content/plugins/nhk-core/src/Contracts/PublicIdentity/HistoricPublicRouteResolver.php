<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\PublicIdentity;

interface HistoricPublicRouteResolver
{
    /** @return array{status:string,target?:string,hops?:int} */
    public function resolveHistoric(string $path): array;
}
