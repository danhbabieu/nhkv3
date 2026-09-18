<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Home;

/** Read-only bounded candidate boundary for the homepage latest projection. */
interface BoundedLatestFeedReader
{
    /** @return list<object> */
    public function latestFeedCandidates(int $limit): array;
}
