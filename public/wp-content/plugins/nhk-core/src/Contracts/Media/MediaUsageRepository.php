<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

use NHK\Core\Domain\Media\MediaUsage;

interface MediaUsageRepository
{
    public function create(MediaUsage $usage): MediaUsage;
    /** @return list<MediaUsage> */
    public function listByMediaId(string $mediaId, ?string $role = null): array;
    /** @return list<MediaUsage> */
    public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array;
}
