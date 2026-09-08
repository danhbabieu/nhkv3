<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Projection;

interface ProjectionStorageStatus
{
    /** @return array{status:string,reason:?string,missing_tables:list<string>} */
    public function status(): array;
}
