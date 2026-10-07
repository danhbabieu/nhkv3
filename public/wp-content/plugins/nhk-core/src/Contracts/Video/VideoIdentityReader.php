<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Video;

interface VideoIdentityReader
{
    /** @return array{canonical_video_id:string,platform:string,external_video_id:string}|null */
    public function findVideoIdentity(string $canonicalVideoId): ?array;
}
