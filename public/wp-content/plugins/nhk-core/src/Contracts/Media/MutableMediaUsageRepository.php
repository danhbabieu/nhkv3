<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

interface MutableMediaUsageRepository extends MediaUsageRepository
{
    public function removeByEndpointRole(string $endpointType, string $endpointKey, string $role): int;
}
