<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\PublicIdentity;

/** Read-only adapter over the existing global route ownership sources. */
interface RootRouteOwnershipReader
{
    /**
     * @return array{status:string,owners:list<array<string,mixed>>,source:string}
     */
    public function inspect(string $path): array;
}
