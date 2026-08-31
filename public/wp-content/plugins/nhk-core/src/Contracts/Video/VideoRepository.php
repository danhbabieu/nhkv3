<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Video;

use NHK\Core\Domain\Video\Video;

interface VideoRepository
{
    public function findByCanonicalId(string $id): ?Video;
    public function findByExternalReference(string $platform, string $externalId): ?Video;
    public function create(Video $video): Video;
    public function update(Video $video, int $expectedRevision): Video;
    /** @return list<Video> */
    public function list(bool $includeRetired = false): array;
}
