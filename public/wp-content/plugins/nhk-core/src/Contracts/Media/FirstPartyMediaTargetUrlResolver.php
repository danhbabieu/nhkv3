<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

interface FirstPartyMediaTargetUrlResolver
{
    /** @return array{type:string,id:string} */
    public function resolve(string $url): array;
}
