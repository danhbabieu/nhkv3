<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Knowledge;

use NHK\Core\Domain\Knowledge\KnowledgeClaim;

/** Optional bounded candidate lookup for read-only Knowledge quality audits. */
interface KnowledgeQualityCandidateReader
{
    /** @return list<KnowledgeClaim> */
    public function qualityCandidates(KnowledgeClaim $claim, int $limit): array;
}
