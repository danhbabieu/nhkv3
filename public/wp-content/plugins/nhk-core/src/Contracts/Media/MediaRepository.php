<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

use NHK\Core\Domain\Media\Media;

interface MediaRepository
{
    public function findByCanonicalId(string $id): ?Media;
    public function findByStableKey(string $stableKey): ?Media;
    public function create(Media $media): Media;
    public function update(Media $media, int $expectedRevision): Media;
    /** @return list<Media> */
    public function list(bool $includeRetired = false): array;
}
