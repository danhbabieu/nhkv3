<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

use NHK\Core\Domain\Media\MediaUsage;

interface ArticleMediaUsageInventory
{
    /** @return array{items:list<MediaUsage>,next_cursor:?string} */
    public function page(string $endpointType, ?string $afterUsageId, int $limit): array;
}
