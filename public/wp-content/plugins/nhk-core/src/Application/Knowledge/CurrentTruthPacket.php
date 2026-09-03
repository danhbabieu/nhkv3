<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, KnowledgeFacetProfile};

final readonly class CurrentTruthPacket
{
    /** @param list<KnowledgeClaim> $claims @param list<Evidence> $qualifiers @param list<Evidence> $contradictions */
    public function __construct(public string $subjectId, public KnowledgeFacetProfile $profile, public array $claims, public array $qualifiers, public array $contradictions, public array $evidenceCoverage = []) {}
}
